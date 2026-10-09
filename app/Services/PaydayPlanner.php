<?php

namespace App\Services;

use App\Support\Money;
use App\Models\PaydayPlan;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Splits a paycheck across bills, debts, essentials, savings and a buffer,
 * then turns whatever is left into a daily spending allowance that lasts
 * until the next payday.
 */
class PaydayPlanner
{
    private const ESSENTIALS = ['Groceries' => 0.12, 'Transport' => 0.07, 'Health' => 0.03];

    public function __construct(private FinanceService $finance) {}

    public function plan(User $user, ?float $income = null, ?Carbon $payday = null, ?Carbon $nextPayday = null, bool $save = false): array
    {
        if (! $payday) {
            // Plan today's paycheck if it is payday, otherwise the upcoming one.
            [$last, $next] = $this->finance->payPeriod($user);
            $payday = $last->isToday() ? $last : $next;
        }
        $payday = $payday->copy()->startOfDay();
        $income ??= $this->finance->incomePerPaycheck($user);
        $nextPayday ??= $this->finance->shiftPayday($payday, $user->pay_frequency, 1);
        $days = max(1, (int) $payday->diffInDays($nextPayday));
        $fraction = $days / 30.44;
        $warnings = [];
        $items = [];

        // 1 + 2. Bills and minimum debt payments due before next payday (mandatory).
        foreach ($this->finance->billsDueBetween($user, $payday, $nextPayday) as $row) {
            $items[] = [
                'group' => $row['bill']->is_debt ? 'debt' : 'bills',
                'name' => $row['bill']->name,
                'amount' => $row['bill']->amount,
                'due' => $row['due']->toDateString(),
                'bill_id' => $row['bill']->id,
            ];
        }
        $mandatory = array_sum(array_column($items, 'amount'));
        $left = $income - $mandatory;
        if ($left < 0) {
            $warnings[] = sprintf('Bills due before %s add up to %s more than this paycheck. Prioritise rent, utilities and minimum debt payments, and contact creditors early about the rest.', $nextPayday->format('M j'), $this->money($user, -$left));
        }

        // 3. Variable essentials scaled to the length of the pay period.
        $budgets = $user->budgets()->with('category')->whereDate('month', $payday->copy()->startOfMonth()->toDateString())->get()->keyBy('category.name');
        $essentials = [];
        foreach (self::ESSENTIALS as $name => $share) {
            $monthly = $budgets->has($name) ? $budgets[$name]->amount : $user->monthly_income * $share;
            if ($monthly > 0) {
                $essentials[] = ['group' => 'essentials', 'name' => $name, 'amount' => round($monthly * $fraction, 2)];
            }
        }

        // 4. Savings goals (or a starter emergency fund when there are none).
        $savings = [];
        foreach ($user->savingsGoals()->orderBy('priority')->get() as $goal) {
            $need = round($this->finance->goalMonthlyNeed($goal, $payday) * $fraction, 2);
            if ($need > 0) {
                $savings[] = ['group' => 'savings', 'name' => $goal->name, 'amount' => $need, 'goal_id' => $goal->id];
            }
        }
        if (! $savings && $income > 0) {
            $savings[] = ['group' => 'savings', 'name' => 'Emergency fund', 'amount' => round($income * 0.10, 2)];
        }

        // 5. Buffer for surprises.
        $buffer = [['group' => 'buffer', 'name' => 'Safety buffer', 'amount' => round($income * FinanceService::BUFFER_RATE, 2)]];

        // Fit flexible items into what's left: essentials first, then buffer, then savings.
        foreach ([&$essentials, &$buffer, &$savings] as &$group) {
            $want = array_sum(array_column($group, 'amount'));
            $ratio = $want > 0 ? max(0, min(1, $left / $want)) : 1;
            if ($ratio < 1 && $want > 0) {
                $label = $group[0]['group'];
                $warnings[] = sprintf('Only %d%% of the planned %s fits in this paycheck.', round($ratio * 100), $label);
                foreach ($group as &$item) {
                    $item['amount'] = round($item['amount'] * $ratio, 2);
                }
                unset($item);
            }
            $left -= array_sum(array_column($group, 'amount'));
        }
        unset($group);
        $items = array_merge($items, $essentials, $savings, $buffer);

        // 6. Surplus beyond comfortable daily spending goes to the highest-interest debt (avalanche).
        $left = round(max(0, $left), 2);
        $rate = $this->finance->dailySpendRate($user);
        $comfortable = $rate > 0 ? $rate * 1.1 * $days : $left;
        if ($left > $comfortable) {
            $debt = $user->bills()->where('is_debt', true)->where('debt_balance', '>', 0)->orderByDesc('interest_rate')->first();
            if ($debt) {
                $extra = round(min($debt->debt_balance, ($left - $comfortable) * 0.5), 2);
                if ($extra > 0) {
                    $items[] = ['group' => 'debt', 'name' => "Extra payment: {$debt->name}", 'amount' => $extra, 'bill_id' => $debt->id, 'extra' => true];
                    $left -= $extra;
                }
            }
        }

        $daily = round($left / $days, 2);
        $items[] = ['group' => 'daily', 'name' => 'Daily spending money', 'amount' => round($left, 2)];

        if ($rate > 0 && $daily < $rate * 0.8) {
            $warnings[] = sprintf('Your allowance is %s/day but you have been spending about %s/day. Cutting dining out and shopping will make this paycheck last.', $this->money($user, $daily), $this->money($user, $rate));
        }

        $result = [
            'payday' => $payday->toDateString(),
            'next_payday' => $nextPayday->toDateString(),
            'days' => $days,
            'income' => round($income, 2),
            'allocations' => $items,
            'totals' => collect($items)->groupBy('group')->map(fn ($g) => round($g->sum('amount'), 2))->all(),
            'daily_allowance' => $daily,
            'weekly_allowance' => round($daily * 7, 2),
            'warnings' => $warnings,
        ];

        if ($save) {
            $plan = $user->paydayPlans()->create([
                'payday' => $result['payday'],
                'next_payday' => $result['next_payday'],
                'income' => $result['income'],
                'allocations' => $items,
                'daily_allowance' => $daily,
            ]);
            $result['id'] = $plan->id;
        }

        return $result;
    }

    private function money(User $user, float $amount): string
    {
        return Money::format($amount, $user->currency);
    }
}
