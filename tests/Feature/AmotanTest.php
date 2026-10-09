<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Services\SharedPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AmotanTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        $u = User::factory()->create(['email' => $email, 'current_balance' => 500, 'onboarded' => true]);
        Category::seedDefaultsFor($u);

        return $u;
    }

    public function test_every_user_gets_an_auto_generated_share_code_that_can_be_reset(): void
    {
        $ana = $this->user('ana@x.test');
        $this->assertMatchesRegularExpression('/^AMO-[2-9A-HJ-NP-Z]{6}$/', $ana->share_code);

        $old = $ana->share_code;
        Sanctum::actingAs($ana);
        $this->getJson('/api/me')->assertJsonPath('share_code', $old);
        $new = $this->postJson('/api/profile/share-code')->assertOk()->json('share_code');
        $this->assertNotSame($old, $new);
        $this->getJson('/api/connect/'.$old)->assertNotFound();
        $this->getJson('/api/connect/'.$new)->assertOk()->assertJsonPath('is_self', true);
    }

    public function test_scanning_a_personal_qr_connects_buddies_both_ways(): void
    {
        [$ana, $ben] = [$this->user('ana@x.test'), $this->user('ben@x.test')];

        Sanctum::actingAs($ben);
        $link = url('/u/'.$ana->share_code);
        $this->getJson('/api/connect/'.urlencode(strtolower(str_replace('-', '', $ana->share_code))))
            ->assertOk()->assertJsonPath('name', $ana->name)->assertJsonPath('already_connected', false);
        $this->postJson('/api/connect/'.$ana->share_code)->assertCreated();
        $this->postJson('/api/connect/'.$ana->share_code)->assertCreated();
        $this->getJson('/api/connections')->assertJsonCount(1)->assertJsonPath('0.name', $ana->name);
        $this->postJson('/api/connect/'.$ben->share_code)->assertStatus(422);
        $this->assertSame($ana->share_code, User::normalizeShareCode($link));

        Sanctum::actingAs($ana);
        $this->getJson('/api/connections')->assertJsonPath('0.id', $ben->id);
        $this->assertArrayNotHasKey('email', $this->getJson('/api/connections')->json('0'));

        // Buddies can be invited to a group plan without typing an email.
        $plan = $this->postJson('/api/plans', ['name' => 'Rent', 'visibility' => 'group'])->json();
        $this->postJson("/api/plans/{$plan['id']}/invites", ['user_ids' => [$ben->id, 9999]])->assertOk();
        Sanctum::actingAs($ben);
        $this->getJson('/api/plans')->assertJsonCount(1, 'invites');

        $this->deleteJson('/api/connections/'.$ana->id)->assertNoContent();
        $this->getJson('/api/connections')->assertJsonCount(0);
    }

    public function test_offline_replays_with_the_same_idempotency_key_are_applied_once(): void
    {
        $ana = $this->user('ana@x.test');
        Sanctum::actingAs($ana);
        $body = ['type' => 'expense', 'amount' => 12, 'description' => 'Lunch', 'occurred_on' => now()->toDateString()];

        $first = $this->postJson('/api/transactions', $body, ['Idempotency-Key' => 'k-1'])->assertCreated()->json();
        $again = $this->postJson('/api/transactions', $body, ['Idempotency-Key' => 'k-1'])->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')->json();

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(1, $ana->transactions()->count());
        $this->assertEquals(488, $ana->fresh()->current_balance);
    }

    public function test_fair_share_keeps_capacity_private_and_shows_gap_instead_of_overcharging(): void
    {
        [$ana, $ben, $cy] = [$this->user('ana@x.test'), $this->user('ben@x.test'), $this->user('cy@x.test')];

        Sanctum::actingAs($ana);
        $plan = $this->postJson('/api/plans', [
            'name' => 'Trip', 'visibility' => 'group', 'target_amount' => 3000, 'target_date' => now()->addDays(60)->toDateString(),
        ])->json();
        foreach ([$ben, $cy] as $u) {
            Sanctum::actingAs($u);
            $this->postJson('/api/join/'.$plan['invite_code'])->assertOk();
        }

        // 2 months left, 1500/month needed, split evenly with no limits set.
        $fair = $this->getJson("/api/plans/{$plan['id']}")->json('fair');
        $this->assertEquals(1500, $fair['monthly_need']);
        $this->assertEquals(500, $fair['me']['suggested']);

        // Cy can only afford 200 and Ben pauses this month.
        $this->patchJson("/api/plans/{$plan['id']}/me", ['capacity' => 200])->assertOk()->assertJsonPath('fair.me.capacity', 200);
        Sanctum::actingAs($ben);
        $fair = $this->patchJson("/api/plans/{$plan['id']}/me", ['paused' => true])->json('fair');
        $this->assertTrue($fair['me']['paused']);
        $this->assertEquals(0, $fair['me']['suggested']);

        Sanctum::actingAs($ana);
        $res = $this->getJson("/api/plans/{$plan['id']}")->assertOk();
        $fair = $res->json('fair');
        $this->assertEquals(1300, $fair['me']['suggested']);
        $this->assertSame(1, $fair['paused_count']);
        $this->assertEquals(0, $fair['gap']);
        $this->assertStringNotContainsString('"capacity":"200', $res->getContent());
        foreach ($res->json('summary.members') as $m) {
            $this->assertArrayNotHasKey('capacity', $m);
        }

        // Ana caps herself at 800 too: the 500/month gap stretches the date instead.
        $fair = $this->patchJson("/api/plans/{$plan['id']}/me", ['capacity' => 800])->json('fair');
        $this->assertEquals(800, $fair['me']['suggested']);
        $this->assertEquals(500, $fair['gap']);
        $this->assertSame(now()->addMonthsNoOverflow(3)->toDateString(), $fair['suggested_date']);
    }

    public function test_distribution_is_proportional_and_capped(): void
    {
        $s = app(SharedPlanService::class);
        $this->assertEquals([1 => 100, 2 => 100, 3 => 100], $s->distribute(300, [1 => null, 2 => null, 3 => null]));
        $this->assertEquals([1 => 50, 2 => 125, 3 => 125], $s->distribute(300, [1 => 50, 2 => null, 3 => null]));
        $this->assertEquals([1 => 100, 2 => 300], $s->distribute(400, [1 => 100, 2 => 300]));
        $this->assertEquals([1 => 100, 2 => 300], $s->distribute(1000, [1 => 100, 2 => 300]));
    }
}
