<?php

namespace App\Services;

use App\Models\SharedPlan;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Totals, per-member shares and "who owes whom" for a shared plan. */
class SharedPlanService
{
    public function summary(SharedPlan $plan): array
    {
        $members = $plan->members()->get(['users.id', 'users.name', 'users.payment_handle']);
        $items = $plan->items()->get();

        $contributed = round($items->where('kind', 'contribution')->sum('amount'), 2);
        $spent = round($items->where('kind', 'expense')->sum('amount'), 2);
        $count = max(1, $members->count());
        $sharePerPerson = round($spent / $count, 2);

        $perMember = $members->map(function (User $m) use ($items, $sharePerPerson, $plan, $count) {
            $paid = round($items->where('user_id', $m->id)->where('kind', 'expense')->sum('amount'), 2);
            $saved = round($items->where('user_id', $m->id)->where('kind', 'contribution')->sum('amount'), 2);
            // Paying someone back raises your balance; receiving a payment lowers theirs.
            $sent = $items->where('kind', 'settlement')->where('user_id', $m->id)->sum('amount');
            $received = $items->where('kind', 'settlement')->where('to_user_id', $m->id)->sum('amount');

            return [
                'id' => $m->id,
                'name' => $m->name,
                'payment_handle' => $m->payment_handle,
                'role' => $m->pivot->role,
                'contributed' => $saved,
                'paid_expenses' => $paid,
                'fair_share' => $sharePerPerson,
                // Positive: the group owes them. Negative: they owe the group.
                'settled_out' => round($sent, 2),
                'settled_in' => round($received, 2),
                'balance' => round($paid - $sharePerPerson + $sent - $received, 2),
                'target_share' => $plan->target_amount ? round($plan->target_amount / $count, 2) : null,
            ];
        })->values();

        return [
            'contributed' => $contributed,
            'spent' => $spent,
            'pot_balance' => round($contributed - $spent, 2),
            'percent' => $plan->target_amount ? round(min(100, $contributed / $plan->target_amount * 100), 1) : null,
            'remaining' => $plan->target_amount ? max(0, round($plan->target_amount - $contributed, 2)) : null,
            'share_per_person' => $sharePerPerson,
            'members' => $perMember->all(),
            'settlements' => $this->settle($perMember->all()),
        ];
    }

    /**
     * Fewest transfers that zero out everyone's balance (greedy: largest
     * debtor pays largest creditor).
     *
     * @return array<int, array{from: string, from_id: int, to: string, to_id: int, amount: float}>
     */
    public function settle(array $members): array
    {
        $handles = collect($members)->pluck('payment_handle', 'id');
        $debtors = collect($members)->filter(fn ($m) => $m['balance'] < -0.009)->map(fn ($m) => ['id' => $m['id'], 'name' => $m['name'], 'amt' => -$m['balance']])->sortByDesc('amt')->values()->all();
        $creditors = collect($members)->filter(fn ($m) => $m['balance'] > 0.009)->map(fn ($m) => ['id' => $m['id'], 'name' => $m['name'], 'amt' => $m['balance']])->sortByDesc('amt')->values()->all();

        $out = [];
        $i = $j = 0;
        while ($i < count($debtors) && $j < count($creditors)) {
            $amount = round(min($debtors[$i]['amt'], $creditors[$j]['amt']), 2);
            if ($amount > 0) {
                $out[] = ['from' => $debtors[$i]['name'], 'from_id' => $debtors[$i]['id'], 'to' => $creditors[$j]['name'], 'to_id' => $creditors[$j]['id'], 'to_payment_handle' => $handles[$creditors[$j]['id']] ?? null, 'amount' => $amount];
            }
            $debtors[$i]['amt'] -= $amount;
            $creditors[$j]['amt'] -= $amount;
            if ($debtors[$i]['amt'] < 0.01) {
                $i++;
            }
            if ($creditors[$j]['amt'] < 0.01) {
                $j++;
            }
        }

        return $out;
    }

    /**
     * Fair-share contributions toward the remaining target. Members may set a
     * private monthly comfort amount (capacity); suggestions are proportional to
     * it and never exceed it. Paused members are skipped anonymously. When the
     * group cannot cover the monthly need, the gap and a realistic new date are
     * reported instead of pushing the shortfall onto everyone else.
     */
    public function fairPlan(SharedPlan $plan, User $viewer, ?Carbon $today = null): ?array
    {
        $today ??= Carbon::today();
        $summary = $this->summary($plan);
        $remaining = $summary['remaining'];
        if (! $plan->target_amount || $remaining <= 0) {
            return null;
        }

        $months = $plan->target_date && $plan->target_date->gt($today)
            ? max(1, (int) ceil($today->diffInDays($plan->target_date) / 30.44))
            : 1;
        $need = round($remaining / $months, 2);

        $members = $plan->members()->get();
        $isPaused = fn (User $m) => $m->pivot->paused_until && Carbon::parse($m->pivot->paused_until)->gte($today);
        $active = $members->reject($isPaused)->values();
        $alloc = $this->distribute($need, $active->mapWithKeys(fn (User $m) => [$m->id => $m->pivot->capacity === null ? null : (float) $m->pivot->capacity])->all());

        $covered = round(array_sum($alloc), 2);
        $gap = round(max(0, $need - $covered), 2);
        $suggestedDate = $gap > 0 && $covered > 0
            ? $today->copy()->addMonthsNoOverflow((int) ceil($remaining / $covered))->toDateString()
            : null;
        $me = $members->firstWhere('id', $viewer->id);

        return [
            'months_left' => $months,
            'monthly_need' => $need,
            'covered' => $covered,
            'gap' => $gap,
            'paused_count' => $members->count() - $active->count(),
            'members_with_capacity' => $active->filter(fn ($m) => $m->pivot->capacity !== null)->count(),
            'suggested_date' => $suggestedDate,
            'me' => [
                'suggested' => round($alloc[$viewer->id] ?? 0, 2),
                'capacity' => $me?->pivot->capacity === null ? null : (float) $me->pivot->capacity,
                'paused' => $me ? $isPaused($me) : false,
            ],
        ];
    }

    /**
     * Water-filling split of $need. A null capacity means "no limit set" and
     * weighs like an equal share; set capacities act as both weight and cap.
     *
     * @param  array<int, float|null>  $capacities
     * @return array<int, float>
     */
    public function distribute(float $need, array $capacities): array
    {
        $alloc = array_fill_keys(array_keys($capacities), 0.0);
        if (! $capacities || $need <= 0) {
            return $alloc;
        }
        $equal = $need / count($capacities);
        $open = array_keys($capacities);
        $left = $need;

        for ($guard = 0; $left > 0.005 && $open && $guard < 50; $guard++) {
            $weights = [];
            foreach ($open as $id) {
                $weights[$id] = $capacities[$id] === null ? $equal : max(0, $capacities[$id]);
            }
            $total = array_sum($weights);
            if ($total <= 0) {
                break;
            }
            $spent = 0;
            foreach ($open as $k => $id) {
                $give = $left * $weights[$id] / $total;
                if ($capacities[$id] !== null) {
                    $give = min($give, $capacities[$id] - $alloc[$id]);
                }
                $alloc[$id] += $give;
                $spent += $give;
                if ($capacities[$id] !== null && $alloc[$id] >= $capacities[$id] - 0.005) {
                    unset($open[$k]);
                }
            }
            $left -= $spent;
            if ($spent < 0.005) {
                break;
            }
        }

        return array_map(fn ($v) => round($v, 2), $alloc);
    }
}
