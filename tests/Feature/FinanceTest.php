<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-09 10:00:00');
        $this->user = User::factory()->create([
            'monthly_income' => 3000,
            'pay_frequency' => 'monthly',
            'next_payday' => '2026-10-25',
            'current_balance' => 1000,
            'onboarded' => true,
        ]);
        Category::seedDefaultsFor($this->user);
        Sanctum::actingAs($this->user);
    }

    private function cat(string $name): int
    {
        return $this->user->categories()->where('name', $name)->value('id');
    }

    public function test_transactions_keep_balance_in_sync_and_auto_categorise(): void
    {
        $tx = $this->postJson('/api/transactions', ['type' => 'expense', 'amount' => 12.5, 'description' => 'Lunch at Chipotle', 'occurred_on' => '2026-10-09'])
            ->assertCreated()
            ->assertJsonPath('category.name', 'Dining Out');
        $this->assertEquals(987.5, $this->user->fresh()->current_balance);

        $this->putJson("/api/transactions/{$tx->json('id')}", ['type' => 'expense', 'amount' => 20, 'occurred_on' => '2026-10-09'])->assertOk();
        $this->assertEquals(980, $this->user->fresh()->current_balance);

        $this->deleteJson("/api/transactions/{$tx->json('id')}")->assertNoContent();
        $this->assertEquals(1000, $this->user->fresh()->current_balance);
    }

    public function test_users_cannot_touch_each_others_data(): void
    {
        $other = User::factory()->create();
        Category::seedDefaultsFor($other);
        $tx = $other->transactions()->create(['type' => 'expense', 'amount' => 5, 'occurred_on' => '2026-10-01']);
        $foreignCategory = $other->categories()->first();

        $this->deleteJson("/api/transactions/{$tx->id}")->assertNotFound();
        $this->postJson('/api/transactions', ['type' => 'expense', 'amount' => 5, 'occurred_on' => '2026-10-09', 'category_id' => $foreignCategory->id])
            ->assertJsonValidationErrors('category_id');
    }

    public function test_safe_to_spend_sets_aside_unpaid_bills_before_payday(): void
    {
        $this->user->bills()->create(['name' => 'Internet', 'amount' => 60, 'due_day' => 15]);
        $this->user->bills()->create(['name' => 'Rent', 'amount' => 900, 'due_day' => 1]); // next due Nov 1, after payday

        $sts = app(FinanceService::class)->safeToSpend($this->user->fresh());

        $this->assertSame('2026-10-25', $sts['next_payday']);
        $this->assertSame(16, $sts['days_left']);
        $this->assertEquals(60, $sts['bills_total']);
        $this->assertEquals(940, $sts['safe_to_spend']);
        $this->assertEquals(round(940 / 16, 2), $sts['daily_allowance']);
    }

    public function test_paying_a_bill_logs_expense_and_reduces_debt(): void
    {
        $bill = $this->user->bills()->create(['name' => 'Card', 'amount' => 100, 'due_day' => 20, 'is_debt' => true, 'debt_balance' => 1000, 'interest_rate' => 20]);

        $this->postJson("/api/bills/{$bill->id}/pay")->assertOk()->assertJsonPath('debt_balance', 900);

        $this->assertEquals(900, $this->user->fresh()->current_balance);
        $this->assertDatabaseHas('transactions', ['user_id' => $this->user->id, 'source' => 'bill', 'category_id' => $this->cat('Debt Payments')]);
        $this->assertEquals(0, app(FinanceService::class)->safeToSpend($this->user->fresh())['bills_total']);
    }

    public function test_stale_payday_rolls_forward(): void
    {
        $this->user->update(['next_payday' => '2026-08-25']);

        [$last, $next] = app(FinanceService::class)->payPeriod($this->user->fresh());

        $this->assertSame('2026-09-25', $last->toDateString());
        $this->assertSame('2026-10-25', $next->toDateString());
        $this->assertSame('2026-10-25', $this->user->fresh()->next_payday->toDateString());
    }

    public function test_payday_plan_allocates_whole_paycheck(): void
    {
        $this->user->bills()->create(['name' => 'Rent', 'amount' => 900, 'due_day' => 1]);
        $this->user->savingsGoals()->create(['name' => 'Trip', 'target_amount' => 1200, 'monthly_contribution' => 100]);

        $plan = $this->getJson('/api/payday?income=3000&payday=2026-10-25&next_payday=2026-11-25')->assertOk()->json();

        $this->assertEquals(3000, round(array_sum(array_column($plan['allocations'], 'amount')), 2));
        $this->assertContains('Rent', array_column($plan['allocations'], 'name'));
        $this->assertGreaterThan(0, $plan['daily_allowance']);
        $this->assertSame(31, $plan['days']);
    }

    public function test_payday_plan_warns_when_bills_exceed_paycheck(): void
    {
        $this->user->bills()->create(['name' => 'Rent', 'amount' => 2500, 'due_day' => 1]);

        $plan = $this->getJson('/api/payday?income=2000&payday=2026-10-25&next_payday=2026-11-25')->assertOk()->json();

        $this->assertNotEmpty($plan['warnings']);
        $this->assertEquals(0, $plan['daily_allowance']);
    }

    public function test_generated_budget_fits_income_and_covers_bills(): void
    {
        $this->user->bills()->create(['name' => 'Rent', 'amount' => 900, 'due_day' => 1, 'category_id' => $this->cat('Housing')]);

        $budget = $this->postJson('/api/budget/generate')->assertOk()->json();

        $total = array_sum(array_column($budget['lines'], 'amount'));
        $this->assertLessThanOrEqual(3000.01, $total);
        $this->assertGreaterThanOrEqual(300, $budget['split']['savings']);
        $housing = collect($budget['lines'])->firstWhere('name', 'Housing');
        $this->assertEquals(900, $housing['amount']);
    }

    public function test_overspending_raises_alert_and_it_clears_when_fixed(): void
    {
        $this->user->budgets()->create(['category_id' => $this->cat('Dining Out'), 'month' => '2026-10-01', 'amount' => 50]);
        $tx = $this->postJson('/api/transactions', ['type' => 'expense', 'amount' => 70, 'category_id' => $this->cat('Dining Out'), 'occurred_on' => '2026-10-08'])->json();

        $alerts = $this->getJson('/api/alerts')->assertOk()->json();
        $this->assertContains('danger', array_column($alerts, 'level'));
        $this->assertTrue(collect($alerts)->contains(fn ($a) => str_contains($a['title'], 'Dining Out is over budget')));

        $this->deleteJson("/api/transactions/{$tx['id']}");
        $alerts = $this->getJson('/api/alerts')->json();
        $this->assertFalse(collect($alerts)->contains(fn ($a) => str_contains($a['title'], 'Dining Out')));
    }

    public function test_affordability_verdicts(): void
    {
        $this->postJson('/api/ai/affordability', ['amount' => 100])->assertOk()->assertJsonPath('verdict', 'yes');
        $this->postJson('/api/ai/affordability', ['amount' => 5000])->assertOk()->assertJsonPath('verdict', 'no');
    }

    public function test_goal_contribution_moves_money_and_projects_eta(): void
    {
        $goal = $this->postJson('/api/goals', ['name' => 'Laptop', 'target_amount' => 1000, 'monthly_contribution' => 100])->assertCreated()->json();

        $res = $this->postJson("/api/goals/{$goal['id']}/contribute", ['amount' => 200])->assertOk()->json();

        $this->assertEquals(200, $res['saved_amount']);
        $this->assertEquals(800, $res['projection']['remaining']);
        $this->assertNotNull($res['projection']['eta']);
        $this->assertEquals(800, $this->user->fresh()->current_balance);
    }

    public function test_dashboard_and_ai_context_load(): void
    {
        $this->getJson('/api/dashboard')->assertOk()->assertJsonStructure(['safe_to_spend', 'month', 'trend', 'recent', 'goals', 'alerts']);
        $this->getJson('/api/ai/context')->assertOk()->assertJsonStructure(['user', 'today', 'safe_to_spend', 'month', 'goals', 'bills', 'categories']);
        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => 'hi']]])->assertNotFound();
    }
}
