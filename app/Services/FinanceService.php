<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Category;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Deterministic money math. Every number the AI assistant talks about comes
 * from here, so answers stay correct even when a small on-device model phrases them.
 */
class FinanceService
{
    /** Share of income kept aside as a buffer before calculating daily spending money. */
    public const BUFFER_RATE = 0.05;

    /**
     * The current pay period as [lastPayday, nextPayday]. Rolls a stale
     * next_payday forward so the app never plans against a date in the past.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function payPeriod(User $user, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $next = $user->next_payday ? Carbon::parse($user->next_payday)->startOfDay() : $today->copy()->endOfMonth()->startOfDay();

        while ($next->lte($today)) {
            $next = $this->shiftPayday($next, $user->pay_frequency, 1);
        }
        if ($user->next_payday === null || ! $next->isSameDay($user->next_payday)) {
            $user->forceFill(['next_payday' => $next->toDateString()])->saveQuietly();
        }

        return [$this->shiftPayday($next, $user->pay_frequency, -1), $next];
    }

    public function shiftPayday(Carbon $date, string $frequency, int $direction): Carbon
    {
        $d = $date->copy();

        return match ($frequency) {
            'weekly' => $d->addDays(7 * $direction),
            'biweekly' => $d->addDays(14 * $direction),
            'semimonthly' => $this->shiftSemimonthly($d, $direction),
            default => $direction > 0 ? $d->addMonthNoOverflow() : $d->subMonthNoOverflow(),
        };
    }

    /** Semi-monthly paydays fall on the 15th and the last day of the month. */
    private function shiftSemimonthly(Carbon $d, int $direction): Carbon
    {
        if ($direction > 0) {
            return $d->day < 15 ? $d->day(15) : ($d->isLastOfMonth() ? $d->addDay()->day(15) : $d->endOfMonth()->startOfDay());
        }

        return $d->day > 15 ? $d->day(15) : $d->subMonthNoOverflow()->endOfMonth()->startOfDay();
    }

    /** Income for one pay period, derived from the monthly figure. */
    public function incomePerPaycheck(User $user): float
    {
        return round(match ($user->pay_frequency) {
            'weekly' => $user->monthly_income * 12 / 52,
            'biweekly' => $user->monthly_income * 12 / 26,
            'semimonthly' => $user->monthly_income / 2,
            default => $user->monthly_income,
        }, 2);
    }

    /**
     * Unpaid bill occurrences due in [$start, $end).
     *
     * @return Collection<int, array{bill: Bill, due: Carbon}>
     */
    public function billsDueBetween(User $user, Carbon $start, Carbon $end): Collection
    {
        return $user->bills()->with('category')->get()
            ->flatMap(fn (Bill $bill) => collect($bill->dueDatesBetween($start, $end))
                ->reject(fn (Carbon $due) => $bill->isPaidFor($due))
                ->map(fn (Carbon $due) => ['bill' => $bill, 'due' => $due]))
            ->sortBy(fn ($row) => $row['due']->timestamp)
            ->values();
    }

    /** Amount a goal needs each month to hit its target date (or its chosen contribution). */
    public function goalMonthlyNeed(SavingsGoal $goal, ?Carbon $today = null): float
    {
        $remaining = max(0, $goal->target_amount - $goal->saved_amount);
        if ($remaining <= 0) {
            return 0;
        }
        if ($goal->target_date) {
            $months = max(1, ($today ?? Carbon::today())->diffInMonths($goal->target_date, false));

            return round(max($remaining / $months, $goal->monthly_contribution ?? 0), 2);
        }

        return round($goal->monthly_contribution ?? 0, 2);
    }

    /** Progress, required pace and estimated completion date for a goal. */
    public function goalProjection(SavingsGoal $goal, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $remaining = max(0, round($goal->target_amount - $goal->saved_amount, 2));

        $recent = (float) $goal->contributions()->where('contributed_on', '>=', $today->copy()->subDays(90))->sum('amount');
        $observedMonthly = round($recent / 3, 2);
        $pace = $goal->monthly_contribution ?: $observedMonthly;

        $eta = null;
        if ($remaining <= 0) {
            $eta = $today->toDateString();
        } elseif ($pace > 0) {
            $eta = $today->copy()->addDays((int) ceil($remaining / $pace * 30.44))->toDateString();
        }

        $required = null;
        $onTrack = null;
        if ($goal->target_date && $remaining > 0) {
            $months = max(1, $today->diffInMonths($goal->target_date, false));
            $required = round($remaining / $months, 2);
            $onTrack = $pace >= $required;
        }

        return [
            'remaining' => $remaining,
            'percent' => $goal->target_amount > 0 ? round(min(100, $goal->saved_amount / $goal->target_amount * 100), 1) : 0,
            'monthly_pace' => round($pace, 2),
            'observed_monthly' => $observedMonthly,
            'required_monthly' => $required,
            'on_track' => $onTrack,
            'eta' => $eta,
        ];
    }

    /** Spending by category for a month, joined with its budget. */
    public function monthSummary(User $user, ?Carbon $month = null): array
    {
        $month = ($month ?? Carbon::today())->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $spent = $user->transactions()
            ->where('type', 'expense')
            ->whereBetween('occurred_on', [$month->toDateString(), $end->toDateString()])
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        $billSpentByCat = $user->transactions()
            ->where('type', 'expense')->where('source', 'bill')
            ->whereBetween('occurred_on', [$month->toDateString(), $end->toDateString()])
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        $income = (float) $user->transactions()->where('type', 'income')
            ->whereBetween('occurred_on', [$month->toDateString(), $end->toDateString()])->sum('amount');

        $budgets = $user->budgets()->whereDate('month', $month->toDateString())->pluck('amount', 'category_id');

        $categories = $user->categories()->where('kind', '!=', 'income')->orderBy('id')->get()
            ->map(function (Category $c) use ($spent, $budgets, $billSpentByCat) {
                $s = round((float) ($spent[$c->id] ?? 0), 2);
                $b = isset($budgets[$c->id]) ? (float) $budgets[$c->id] : null;

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'icon' => $c->icon,
                    'color' => $c->color,
                    'kind' => $c->kind,
                    'spent' => $s,
                    'bill_spent' => round((float) ($billSpentByCat[$c->id] ?? 0), 2),
                    'budget' => $b,
                    'remaining' => $b !== null ? round($b - $s, 2) : null,
                    'percent' => $b ? round($s / $b * 100, 1) : null,
                ];
            })
            ->filter(fn ($row) => $row['spent'] > 0 || $row['budget'] !== null)
            ->values();

        $uncategorised = round((float) ($spent[''] ?? 0), 2);
        $totalSpent = round($categories->sum('spent') + $uncategorised, 2);
        $totalBudget = round($categories->sum(fn ($r) => $r['budget'] ?? 0), 2);

        $today = Carbon::today();
        $daysInMonth = $month->daysInMonth;
        $daysElapsed = $today->isSameMonth($month) ? $today->day : ($today->gt($end) ? $daysInMonth : 0);
        // Bills are fixed: count them once (paid + still due), and extrapolate only day-to-day spending.
        $projected = 0;
        if ($daysElapsed > 0) {
            $range = [$month->toDateString(), $end->toDateString()];
            $billSpent = (float) $user->transactions()->where('type', 'expense')->where('source', 'bill')->whereBetween('occurred_on', $range)->sum('amount');
            $billsLeft = $daysElapsed < $daysInMonth
                ? $this->billsDueBetween($user, $month->copy()->day($daysElapsed)->addDay(), $end->copy()->addDay()->startOfDay())->sum(fn ($r) => $r['bill']->amount)
                : 0;
            $variable = $totalSpent - $billSpent;
            $projected = round($billSpent + $billsLeft + $variable / $daysElapsed * $daysInMonth, 2);
        }

        return [
            'month' => $month->format('Y-m'),
            'income' => round($income, 2),
            'total_spent' => $totalSpent,
            'total_budget' => $totalBudget,
            'projected_spend' => $projected,
            'days_elapsed' => $daysElapsed,
            'days_in_month' => $daysInMonth,
            'categories' => $categories->all(),
        ];
    }

    /** Average daily day-to-day spending (excludes bill payments) over the last $days days. */
    public function dailySpendRate(User $user, int $days = 30): float
    {
        $since = Carbon::today()->subDays($days - 1);
        $first = $user->transactions()->where('type', 'expense')->min('occurred_on');
        if (! $first) {
            return 0;
        }
        $span = max(1, min($days, Carbon::parse($first)->diffInDays(Carbon::today()) + 1));
        $total = (float) $user->transactions()->where('type', 'expense')->where('source', '!=', 'bill')
            ->where('occurred_on', '>=', $since->toDateString())->sum('amount');

        return round($total / $span, 2);
    }

    /**
     * How much the user can safely spend until next payday: balance minus unpaid
     * bills before payday and the savings planned for this period.
     */
    public function safeToSpend(User $user): array
    {
        $today = Carbon::today();
        [$last, $next] = $this->payPeriod($user, $today);
        $daysLeft = max(1, (int) $today->diffInDays($next));

        $bills = $this->billsDueBetween($user, $today, $next);
        $billsTotal = round($bills->sum(fn ($r) => $r['bill']->amount), 2);

        $periodDays = max(1, $last->diffInDays($next));
        $periodFraction = $daysLeft / 30.44;
        $savingsReserved = round($user->savingsGoals->sum(fn ($g) => $this->goalMonthlyNeed($g, $today)) * $periodFraction, 2);

        $available = round($user->current_balance - $billsTotal - $savingsReserved, 2);
        $daily = round($available / $daysLeft, 2);

        $rate = $this->dailySpendRate($user);
        $projectedAtPayday = round($user->current_balance - $billsTotal - $rate * $daysLeft, 2);
        $runOutDate = null;
        if ($rate > 0 && $projectedAtPayday < 0) {
            $daysUntilEmpty = (int) floor(max(0, $user->current_balance - $billsTotal) / $rate);
            $runOutDate = $today->copy()->addDays($daysUntilEmpty)->toDateString();
        }

        return [
            'balance' => round($user->current_balance, 2),
            'next_payday' => $next->toDateString(),
            'last_payday' => $last->toDateString(),
            'period_days' => $periodDays,
            'days_left' => $daysLeft,
            'bills_due' => $bills->map(fn ($r) => [
                'id' => $r['bill']->id,
                'name' => $r['bill']->name,
                'amount' => $r['bill']->amount,
                'due' => $r['due']->toDateString(),
            ])->all(),
            'bills_total' => $billsTotal,
            'savings_reserved' => $savingsReserved,
            'safe_to_spend' => $available,
            'daily_allowance' => $daily,
            'daily_spend_rate' => $rate,
            'projected_balance_at_payday' => $projectedAtPayday,
            'run_out_date' => $runOutDate,
        ];
    }

    /** Can the user afford a one-off purchase (or a recurring monthly cost)? */
    public function affordability(User $user, float $amount, bool $monthly = false): array
    {
        $sts = $this->safeToSpend($user);
        $after = round($sts['safe_to_spend'] - $amount, 2);
        $dailyAfter = round($after / $sts['days_left'], 2);
        $discretionaryMonthly = max(0, $user->monthly_income
            - $user->bills->sum('amount')
            - $user->savingsGoals->sum(fn ($g) => $this->goalMonthlyNeed($g)));

        if ($monthly) {
            $share = $discretionaryMonthly > 0 ? $amount / $discretionaryMonthly : 1;
            $verdict = $share <= 0.15 ? 'yes' : ($share <= 0.35 ? 'caution' : 'no');
        } elseif ($after < 0) {
            $verdict = 'no';
        } elseif ($dailyAfter < max(5, $sts['daily_spend_rate'] * 0.5)) {
            $verdict = 'caution';
        } else {
            $verdict = 'yes';
        }

        // How long saving the daily surplus would take to cover it.
        $surplusPerDay = max(0, $sts['daily_allowance'] - $sts['daily_spend_rate']);
        $daysToSave = $surplusPerDay > 0 ? (int) ceil($amount / $surplusPerDay) : null;

        return [
            'amount' => $amount,
            'monthly' => $monthly,
            'verdict' => $verdict,
            'safe_to_spend_before' => $sts['safe_to_spend'],
            'safe_to_spend_after' => $after,
            'daily_allowance_before' => $sts['daily_allowance'],
            'daily_allowance_after' => $dailyAfter,
            'days_left' => $sts['days_left'],
            'next_payday' => $sts['next_payday'],
            'monthly_discretionary' => round($discretionaryMonthly, 2),
            'share_of_discretionary' => $monthly && $discretionaryMonthly > 0 ? round($amount / $discretionaryMonthly * 100, 1) : null,
            'days_to_save' => $daysToSave,
        ];
    }

    /** Pick a category for free text using the per-category keyword lists. */
    public function guessCategory(User $user, string $text, string $type = 'expense'): ?Category
    {
        $text = mb_strtolower($text);
        $categories = $user->categories()->get()
            ->filter(fn ($c) => $type === 'income' ? $c->kind === 'income' : $c->kind !== 'income');

        $best = null;
        $bestLen = 0;
        foreach ($categories as $category) {
            foreach (array_merge([$category->name], $category->keywords ?? []) as $kw) {
                $kw = mb_strtolower($kw);
                if ($kw !== '' && str_contains($text, $kw) && mb_strlen($kw) > $bestLen) {
                    $best = $category;
                    $bestLen = mb_strlen($kw);
                }
            }
        }

        return $best ?? $categories->firstWhere('name', $type === 'income' ? 'Other Income' : 'Other');
    }

    /** Compact, model-friendly snapshot of the user's finances for the AI assistant. */
    public function snapshot(User $user): array
    {
        $user->loadMissing('savingsGoals', 'bills');

        return [
            'user' => [
                'name' => $user->name,
                'currency' => $user->currency,
                'monthly_income' => $user->monthly_income,
                'pay_frequency' => $user->pay_frequency,
            ],
            'today' => Carbon::today()->toDateString(),
            'safe_to_spend' => $this->safeToSpend($user),
            'month' => $this->monthSummary($user),
            'last_month' => collect($this->monthSummary($user, Carbon::today()->subMonthNoOverflow())['categories'])
                ->map(fn ($c) => ['name' => $c['name'], 'spent' => $c['spent']])->all(),
            'goals' => $user->savingsGoals->map(fn ($g) => [
                'name' => $g->name,
                'target' => $g->target_amount,
                'saved' => $g->saved_amount,
                'target_date' => $g->target_date?->toDateString(),
            ] + $this->goalProjection($g))->all(),
            'bills' => $user->bills->map(fn ($b) => [
                'name' => $b->name, 'amount' => $b->amount, 'due_day' => $b->due_day, 'is_debt' => $b->is_debt,
                'debt_balance' => $b->debt_balance, 'interest_rate' => $b->interest_rate,
            ])->all(),
            'shared_plans' => $user->sharedPlans()->get()->map(function ($p) {
                $s = app(SharedPlanService::class)->summary($p);
                $me = collect($s['members'])->firstWhere('id', auth()->id());

                return [
                    'name' => $p->name, 'visibility' => $p->visibility, 'members' => count($s['members']),
                    'target' => $p->target_amount, 'contributed' => $s['contributed'], 'spent' => $s['spent'],
                    'target_date' => $p->target_date?->toDateString(), 'my_contribution' => $me['contributed'] ?? 0,
                    'my_balance' => $me['balance'] ?? 0,
                ];
            })->all(),
            'categories' => $user->categories()->get(['id', 'name', 'icon', 'kind', 'keywords'])->toArray(),
        ];
    }
}
