<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SharedPlanTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        $u = User::factory()->create(['email' => $email, 'current_balance' => 500, 'onboarded' => true]);
        Category::seedDefaultsFor($u);

        return $u;
    }

    public function test_group_plan_join_by_code_contribute_and_settle_up(): void
    {
        [$ana, $ben, $cy] = [$this->user('ana@x.test'), $this->user('ben@x.test'), $this->user('cy@x.test')];

        Sanctum::actingAs($ana);
        $plan = $this->postJson('/api/plans', ['name' => 'Beach day', 'visibility' => 'group', 'target_amount' => 300])->assertCreated()->json();
        $this->assertNotEmpty($plan['invite_code']);
        $this->assertStringEndsWith('/join/'.$plan['invite_code'], $plan['join_url']);

        foreach ([$ben, $cy] as $u) {
            Sanctum::actingAs($u);
            $this->getJson('/api/join/'.strtolower($plan['invite_code']))->assertOk()->assertJsonPath('name', 'Beach day');
            $this->postJson('/api/join/'.$plan['invite_code'])->assertOk();
        }

        Sanctum::actingAs($ana);
        $this->postJson("/api/plans/{$plan['id']}/items", ['kind' => 'expense', 'amount' => 90, 'description' => 'Food'])->assertCreated();
        Sanctum::actingAs($ben);
        $this->postJson("/api/plans/{$plan['id']}/items", ['kind' => 'contribution', 'amount' => 100])->assertCreated();
        $detail = $this->postJson("/api/plans/{$plan['id']}/items", ['kind' => 'expense', 'amount' => 30, 'record_personal' => false])->json();

        $s = $detail['summary'];
        $this->assertCount(3, $s['members']);
        $this->assertEquals(100, $s['contributed']);
        $this->assertEquals(120, $s['spent']);
        $this->assertEquals(40, $s['share_per_person']);
        // Ana paid 90 (gets 50 back), Ben paid 30 (owes 10), Cy paid 0 (owes 40).
        $owed = collect($s['settlements'])->mapWithKeys(fn ($t) => [$t['from_id'] => $t['amount']]);
        $this->assertEquals(40, $owed[$cy->id]);
        $this->assertEquals(10, $owed[$ben->id]);
        $this->assertEquals(50, collect($s['settlements'])->where('to_id', $ana->id)->sum('amount'));

        // Personal mirror: Ben's contribution left his balance, his unrecorded expense did not.
        $this->assertEquals(400, $ben->fresh()->current_balance);
    }

    public function test_email_invite_shows_as_alert_and_can_be_accepted(): void
    {
        [$ana, $ben] = [$this->user('ana@x.test'), $this->user('ben@x.test')];
        Sanctum::actingAs($ana);
        $plan = $this->postJson('/api/plans', ['name' => 'Rent pot', 'visibility' => 'group', 'emails' => ['BEN@x.test']])->json();

        Sanctum::actingAs($ben);
        $this->getJson('/api/alerts')->assertOk()->assertJsonFragment(['title' => '🎉 Invitation: Rent pot']);
        $invites = $this->getJson('/api/plans')->json('invites');
        $this->assertCount(1, $invites);
        $this->postJson("/api/invites/{$invites[0]['id']}", ['accept' => true])->assertOk()->assertJsonPath('name', 'Rent pot');
        $this->getJson("/api/plans/{$plan['id']}")->assertOk();
    }

    public function test_private_plans_cannot_be_joined_or_shared(): void
    {
        [$ana, $ben] = [$this->user('ana@x.test'), $this->user('ben@x.test')];
        Sanctum::actingAs($ana);
        $plan = $this->postJson('/api/plans', ['name' => 'Secret gift', 'visibility' => 'private'])->assertCreated()->json();
        $this->assertNull($plan['invite_code']);
        $this->postJson("/api/plans/{$plan['id']}/invites", ['emails' => ['ben@x.test']])->assertJsonValidationErrors('visibility');

        $code = \App\Models\SharedPlan::find($plan['id'])->invite_code;
        Sanctum::actingAs($ben);
        $this->postJson("/api/join/$code")->assertNotFound();
        $this->getJson("/api/plans/{$plan['id']}")->assertNotFound();
    }

    public function test_group_with_members_cannot_go_private_and_only_owner_manages(): void
    {
        [$ana, $ben] = [$this->user('ana@x.test'), $this->user('ben@x.test')];
        Sanctum::actingAs($ana);
        $plan = $this->postJson('/api/plans', ['name' => 'Trip', 'visibility' => 'group'])->json();
        Sanctum::actingAs($ben);
        $this->postJson('/api/join/'.$plan['invite_code'])->assertOk();
        $this->patchJson("/api/plans/{$plan['id']}", ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson("/api/plans/{$plan['id']}")->assertForbidden();

        Sanctum::actingAs($ana);
        $this->patchJson("/api/plans/{$plan['id']}", ['visibility' => 'private'])->assertJsonValidationErrors('visibility');
        $old = $plan['invite_code'];
        $new = $this->postJson("/api/plans/{$plan['id']}/code")->json('invite_code');
        $this->assertNotSame($old, $new);
        $this->getJson("/api/join/$old")->assertNotFound();
    }

    public function test_deleting_item_removes_mirrored_transaction(): void
    {
        $ana = $this->user('ana@x.test');
        Sanctum::actingAs($ana);
        $plan = $this->postJson('/api/plans', ['name' => 'Dinner', 'visibility' => 'group'])->json();
        $detail = $this->postJson("/api/plans/{$plan['id']}/items", ['kind' => 'expense', 'amount' => 60])->json();
        $this->assertEquals(440, $ana->fresh()->current_balance);

        $this->deleteJson("/api/plans/{$plan['id']}/items/{$detail['items'][0]['id']}")->assertOk();
        $this->assertEquals(500, $ana->fresh()->current_balance);
    }

    public function test_recording_settlements_clears_balances(): void
    {
        [$ana, $ben] = [$this->user('ana@x.test'), $this->user('ben@x.test')];
        $ben->update(['payment_handle' => 'GCash 0917 000 0000']);
        Sanctum::actingAs($ana);
        $plan = $this->postJson('/api/plans', ['name' => 'Dinner', 'visibility' => 'group'])->json();
        Sanctum::actingAs($ben);
        $this->postJson('/api/join/'.$plan['invite_code']);
        $s = $this->postJson("/api/plans/{$plan['id']}/items", ['kind' => 'expense', 'amount' => 100])->json('summary');
        $this->assertEquals([['from_id' => $ana->id, 'to_id' => $ben->id, 'amount' => 50.0, 'to_payment_handle' => 'GCash 0917 000 0000']],
            collect($s['settlements'])->map(fn ($t) => collect($t)->only(['from_id', 'to_id', 'amount', 'to_payment_handle'])->all())->all());

        // Ben (the receiver) confirms Ana paid him; nobody can settle on behalf of two other people.
        $s = $this->postJson("/api/plans/{$plan['id']}/items", ['kind' => 'settlement', 'amount' => 50, 'from_user_id' => $ana->id, 'to_user_id' => $ben->id])->assertCreated()->json('summary');
        $this->assertSame([], $s['settlements']);
        $this->assertEquals(0, collect($s['members'])->sum(fn ($m) => abs($m['balance'])));
        $this->postJson("/api/plans/{$plan['id']}/items", ['kind' => 'settlement', 'amount' => 5, 'to_user_id' => $ben->id])->assertJsonValidationErrors('to_user_id');
        $this->assertEquals(400, $ben->fresh()->current_balance); // settlements never touch personal balances automatically
    }
}
