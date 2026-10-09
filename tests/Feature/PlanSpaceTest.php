<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanSpaceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        $u = User::factory()->create(['email' => $email, 'onboarded' => true]);
        Category::seedDefaultsFor($u);

        return $u;
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function barkada(): array
    {
        [$ana, $ben] = [$this->user('ana@x.test'), $this->user('ben@x.test')];
        Sanctum::actingAs($ana);
        $plan = $this->postJson('/api/plans', ['name' => 'Baguio trip', 'visibility' => 'group'])->json();
        Sanctum::actingAs($ben);
        $this->postJson('/api/join/'.$plan['invite_code'])->assertOk();

        return [$ana, $ben, $plan['id']];
    }

    public function test_members_chat_in_the_plan_room_and_poll_for_new_messages(): void
    {
        [$ana, $ben, $id] = $this->barkada();

        $first = $this->postJson("/api/plans/{$id}/messages", ['body' => 'Bus leaves 6am'])->assertCreated()->assertJsonPath('mine', true)->json('id');
        Sanctum::actingAs($ana);
        $this->postJson("/api/plans/{$id}/messages", ['body' => 'Got it!'])->assertCreated();

        $this->getJson("/api/plans/{$id}/messages")->assertJsonCount(2)
            ->assertJsonPath('0.name', $ben->name)->assertJsonPath('0.mine', false);
        $this->getJson("/api/plans/{$id}/messages?after={$first}")->assertJsonCount(1)->assertJsonPath('0.body', 'Got it!');
    }

    public function test_outsiders_cannot_read_or_write_the_plan_space(): void
    {
        [, , $id] = $this->barkada();
        Sanctum::actingAs($this->user('eve@x.test'));

        $this->getJson("/api/plans/{$id}/messages")->assertNotFound();
        $this->postJson("/api/plans/{$id}/notes", ['title' => 'x'])->assertNotFound();
        $this->getJson("/api/plans/{$id}/events")->assertNotFound();
    }

    public function test_shared_notes_pin_to_top_and_only_author_or_owner_can_delete(): void
    {
        [$ana, $ben, $id] = $this->barkada();

        Sanctum::actingAs($ana);
        $mine = $this->postJson("/api/plans/{$id}/notes", ['title' => 'Packing list', 'body' => 'jacket'])->assertCreated()->json('id');
        Sanctum::actingAs($ben);
        $bens = $this->postJson("/api/plans/{$id}/notes", ['title' => 'Budget idea'])->json('id');
        $this->patchJson("/api/plans/{$id}/notes/{$mine}", ['pinned' => true])->assertOk();

        $this->getJson("/api/plans/{$id}/notes")->assertJsonPath('0.id', $mine);
        $this->deleteJson("/api/plans/{$id}/notes/{$mine}")->assertForbidden();

        Sanctum::actingAs($ana);
        $this->deleteJson("/api/plans/{$id}/notes/{$bens}")->assertNoContent();
    }

    public function test_calendar_events_are_listed_in_date_order(): void
    {
        [, , $id] = $this->barkada();

        $this->postJson("/api/plans/{$id}/events", ['title' => 'Pay deposit', 'date' => '2026-11-20'])->assertCreated();
        $this->postJson("/api/plans/{$id}/events", ['title' => 'Planning call', 'date' => '2026-11-05', 'time' => '19:30'])->assertCreated();
        $this->postJson("/api/plans/{$id}/events", ['title' => 'Bad', 'date' => 'soon'])->assertUnprocessable();

        $this->getJson("/api/plans/{$id}/events")->assertJsonCount(2)
            ->assertJsonPath('0.title', 'Planning call')->assertJsonPath('0.date', '2026-11-05');
    }
}
