<?php

namespace App\Services;

use App\Support\Money;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Builds a personalised monthly budget. Starts from the 50/30/20 rule, then
 * adapts it: fixed bills are locked in, essentials follow the user's real
 * spending history, savings scale to what the goals need, and wants absorb
 * whatever is left.
 */
class BudgetGenerator
{
    /** Default split of the "wants" pot when there is no spending history yet. */
    private const WANT_WEIGHTS = ['Dining Out' => 0.3, 'Shopping' => 0.25, 'Entertainment' => 0.2, 'Personal' => 0.15, 'Other' => 0.1];

    /** Default share of income for variable essentials with no history. */
    private const NEED_DEFAULTS = ['Groceries' => 0.12, 'Transport' => 0.07, 'Health' => 0.03, 'Utilities' => 0.05];

    public function __construct(private FinanceService $finance) {}

    /**
     * @return array{month: string, income: float, lines: array<int, array>, notes: array<int, string>, split: array}
     */
    public function generate(User $user, ?Carbon $month = null, bool $save = true): array
    {
        $month = ($month ?? Carbon::today())->copy()->startOfMonth();
        $income = (float) $user->monthly_income;
        $notes = [];
        $categories = $user->categories()->where('kind', '!=', 'income')->get()->keyBy('name');
        $history = $this->averageMonthlySpend($user, $month);
        $alloc = [];

        // 1. Fixed bills are non-negotiable.
        foreach ($user->bills()->get() as $bill) {
            $cat = $bill->category_id ? $categories->firstWhere('id', $bill->category_id) : $this->categoryForBill($categories, $bill->is_debt);
            if ($cat) {
                $alloc[$cat->id] = ($alloc[$cat->id] ?? 0) + $bill->amount;
            }
        }
        $fixed = array_sum($alloc);

        // 2. Variable essentials: recent average (+5% headroom) or a sensible default.
        foreach ($categories->where('kind', 'need') as $cat) {
            // Categories already covered by a bill only get extra room for non-bill spending seen in history.
            $default = isset($alloc[$cat->id]) ? 0 : $income * (self::NEED_DEFAULTS[$cat->name] ?? 0);
            $base = isset($history[$cat->id]) ? $history[$cat->id] * 1.05 : $default;
            if ($base > 0) {
                $alloc[$cat->id] = ($alloc[$cat->id] ?? 0) + round($base, 2);
            }
        }
        $needs = array_sum($alloc);

        // 3. Savings: what the goals require, at least 10% and ideally 20% of income.
        $goalNeed = $user->savingsGoals()->get()->sum(fn ($g) => $this->finance->goalMonthlyNeed($g, $month));
        $afterNeeds = max(0, $income - $needs);
        $savingsTarget = max($goalNeed, $income * 0.20);
        $savings = min($savingsTarget, $afterNeeds * 0.6);
        $savings = max($savings, min($afterNeeds, $income * 0.10));
        if ($goalNeed > $savings) {
            $notes[] = sprintf('Your goals need %s/month but only %s fits after essentials. Consider pushing a target date back.', $this->money($user, $goalNeed), $this->money($user, $savings));
        }

        // 4. Wants share the remainder, weighted by past behaviour.
        $wantsPot = max(0, $income - $needs - $savings);
        $wantCats = $categories->where('kind', 'want');
        $weights = [];
        foreach ($wantCats as $cat) {
            $weights[$cat->id] = $history[$cat->id] ?? null;
        }
        $historyTotal = array_sum(array_filter($weights));
        foreach ($wantCats as $cat) {
            $w = $historyTotal > 0 ? (($weights[$cat->id] ?? 0) / $historyTotal) : (self::WANT_WEIGHTS[$cat->name] ?? 0.05);
            $alloc[$cat->id] = ($alloc[$cat->id] ?? 0) + round($wantsPot * $w, 2);
        }
        if ($historyTotal > 0 && $historyTotal > $wantsPot * 1.1) {
            $notes[] = sprintf('You usually spend %s on wants, but this budget allows %s. The biggest cut is to your highest-spend category.', $this->money($user, $historyTotal), $this->money($user, $wantsPot));
        }

        if ($savingsCat = $categories->get('Savings')) {
            $alloc[$savingsCat->id] = round($savings, 2);
        }

        if ($needs > $income) {
            $notes[] = sprintf('Essentials and bills (%s) are more than your income (%s). Focus on cutting fixed costs or adding income.', $this->money($user, $needs), $this->money($user, $income));
        }
        if ($fixed > $income * 0.5) {
            $notes[] = 'Fixed bills take over half your income, which leaves little room for surprises. Build a small emergency fund first.';
        }

        $lines = [];
        foreach ($alloc as $categoryId => $amount) {
            $cat = $categories->firstWhere('id', $categoryId);
            $amount = round(max(0, $amount), 2);
            if ($save) {
                $user->budgets()->updateOrCreate(['category_id' => $categoryId, 'month' => $month->toDateString()], ['amount' => $amount]);
            }
            $lines[] = ['category_id' => $categoryId, 'name' => $cat->name, 'icon' => $cat->icon, 'kind' => $cat->kind, 'amount' => $amount];
        }

        $total = fn ($kind) => round(collect($lines)->where('kind', $kind)->sum('amount'), 2);

        return [
            'month' => $month->format('Y-m'),
            'income' => $income,
            'lines' => $lines,
            'notes' => $notes,
            'split' => ['need' => $total('need'), 'want' => $total('want'), 'savings' => $total('savings')],
        ];
    }

    /** Average monthly spend per category over the 3 full months before $month. */
    private function averageMonthlySpend(User $user, Carbon $month): array
    {
        $from = $month->copy()->subMonthsNoOverflow(3);
        $rows = $user->transactions()->where('type', 'expense')->where('source', '!=', 'bill')
            ->where('occurred_on', '>=', $from->toDateString())
            ->where('occurred_on', '<', $month->toDateString())
            ->selectRaw('category_id, SUM(amount) as total, COUNT(DISTINCT DATE_FORMAT(occurred_on, "%Y-%m")) as months')
            ->groupBy('category_id')->get();

        return $rows->filter(fn ($r) => $r->category_id)
            ->mapWithKeys(fn ($r) => [$r->category_id => round($r->total / max(1, $r->months), 2)])->all();
    }

    private function categoryForBill($categories, bool $isDebt): ?Category
    {
        return $isDebt ? $categories->get('Debt Payments') : $categories->get('Utilities');
    }

    private function money(User $user, float $amount): string
    {
        return Money::format($amount, $user->currency);
    }
}
