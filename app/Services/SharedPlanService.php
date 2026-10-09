<?php

namespace App\Services;

use App\Models\SharedPlan;
use App\Models\User;

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
}
