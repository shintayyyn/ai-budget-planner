<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use App\Services\BudgetGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    /** Demo account with ~3 months of realistic activity. Login: demo@budget.test / password */
    public function run(): void
    {
        $today = Carbon::today();
        $user = User::updateOrCreate(['email' => 'demo@budget.test'], [
            'name' => 'Demo User',
            'password' => 'password',
            'currency' => 'USD',
            'monthly_income' => 3800,
            'pay_frequency' => 'biweekly',
            'next_payday' => $today->copy()->addDays(6)->toDateString(),
            'current_balance' => 0,
            'onboarded' => true,
        ]);
        $user->transactions()->delete();
        $user->bills()->delete();
        $user->savingsGoals()->delete();
        $user->budgets()->delete();
        $user->alerts()->delete();
        Category::seedDefaultsFor($user);
        $user->forceFill(['current_balance' => 0])->save();
        $cat = $user->categories()->pluck('id', 'name');

        $bills = [
            ['name' => 'Rent', 'amount' => 1250, 'due_day' => 1, 'category_id' => $cat['Housing']],
            ['name' => 'Electricity', 'amount' => 85, 'due_day' => 12, 'category_id' => $cat['Utilities']],
            ['name' => 'Internet & phone', 'amount' => 70, 'due_day' => 18, 'category_id' => $cat['Utilities']],
            ['name' => 'Streaming', 'amount' => 16, 'due_day' => 22, 'category_id' => $cat['Entertainment']],
            ['name' => 'Credit card', 'amount' => 120, 'due_day' => 25, 'category_id' => $cat['Debt Payments'], 'is_debt' => true, 'debt_balance' => 2400, 'interest_rate' => 22.9],
            ['name' => 'Car loan', 'amount' => 210, 'due_day' => 8, 'category_id' => $cat['Debt Payments'], 'is_debt' => true, 'debt_balance' => 6800, 'interest_rate' => 6.5],
        ];
        foreach ($bills as $b) {
            $user->bills()->create($b);
        }

        mt_srand(42);
        $daily = [
            ['Groceries', ['Whole Foods', 'Aldi', 'Costco', 'Trader Joe\'s'], 25, 95, 0.3],
            ['Dining Out', ['Starbucks', 'Chipotle', 'Lunch with team', 'Pizza night', 'Sushi bar'], 6, 48, 0.55],
            ['Transport', ['Shell fuel', 'Uber', 'Metro card', 'Parking'], 8, 55, 0.25],
            ['Shopping', ['Amazon', 'Target', 'Uniqlo'], 15, 120, 0.12],
            ['Entertainment', ['Cinema', 'Steam game', 'Concert tickets'], 10, 60, 0.07],
            ['Health', ['CVS pharmacy', 'Gym day pass'], 10, 45, 0.05],
            ['Personal', ['Haircut', 'Gift for mom'], 15, 50, 0.04],
        ];

        $start = $today->copy()->subMonthsNoOverflow(3)->startOfMonth();
        // Opening balance before the history starts.
        $user->transactions()->create(['type' => 'income', 'amount' => 2100, 'category_id' => $cat['Other Income'], 'description' => 'Opening balance', 'occurred_on' => $start->toDateString(), 'source' => 'manual']);

        $payday = Carbon::parse($user->next_payday);
        while ($payday->gt($start)) {
            $payday->subDays(14);
        }
        $payday->addDays(14);
        for ($d = $start->copy(); $d->lte($today); $d->addDay()) {
            if ($d->isSameDay($payday)) {
                $user->transactions()->create(['type' => 'income', 'amount' => round(3800 * 12 / 26, 2), 'category_id' => $cat['Salary'], 'description' => 'Paycheck', 'merchant' => 'Acme Corp', 'occurred_on' => $d->toDateString(), 'source' => 'manual']);
                $payday->addDays(14);
            }
            foreach ($user->bills as $bill) {
                if ($d->day === min($bill->due_day, $d->daysInMonth) && $d->lt($today)) {
                    $user->transactions()->create(['type' => 'expense', 'amount' => $bill->amount, 'category_id' => $bill->category_id, 'description' => $bill->name, 'occurred_on' => $d->toDateString(), 'source' => 'bill']);
                    $bill->update(['last_paid_on' => $d->toDateString()]);
                }
            }
            // Spend a bit more in the current month so alerts have something to say.
            $boost = $d->isSameMonth($today) ? 1.35 : 1.0;
            foreach ($daily as [$name, $merchants, $min, $max, $p]) {
                if (mt_rand() / mt_getrandmax() < $p) {
                    $user->transactions()->create([
                        'type' => 'expense',
                        'amount' => round(mt_rand($min * 100, $max * 100) / 100 * $boost, 2),
                        'category_id' => $cat[$name],
                        'merchant' => $m = $merchants[array_rand($merchants)],
                        'description' => $m,
                        'occurred_on' => $d->toDateString(),
                        'source' => mt_rand(0, 4) === 0 ? 'chat' : 'manual',
                    ]);
                }
            }
        }

        $emergency = $user->savingsGoals()->create(['name' => 'Emergency fund', 'icon' => '🛟', 'target_amount' => 5000, 'saved_amount' => 1350, 'monthly_contribution' => 250, 'priority' => 1]);
        $user->savingsGoals()->create(['name' => 'Japan trip', 'icon' => '✈️', 'target_amount' => 3000, 'saved_amount' => 600, 'target_date' => $today->copy()->addMonths(10)->toDateString(), 'priority' => 2]);
        $user->savingsGoals()->create(['name' => 'New laptop', 'icon' => '💻', 'target_amount' => 1400, 'saved_amount' => 900, 'monthly_contribution' => 100, 'priority' => 3]);
        foreach ([60, 30, 1] as $ago) {
            $emergency->contributions()->create(['amount' => 250, 'contributed_on' => $today->copy()->subDays($ago)]);
        }

        $generator = app(BudgetGenerator::class);
        $generator->generate($user->fresh(), $today->copy()->subMonthsNoOverflow(1));
        $generator->generate($user->fresh());
    }
}
