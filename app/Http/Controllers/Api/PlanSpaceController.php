<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlanMessage;
use App\Models\PlanNote;
use App\Models\SharedPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * The barkada space of a plan: chat room, shared notes and calendar.
 * Only plan members can read or write.
 */
class PlanSpaceController extends Controller
{
    /**
     * Latest 100 messages, or only the ones newer than `after` when polling.
     */
    public function messages(Request $request, int $id): Collection
    {
        $plan = $this->memberPlan($request, $id);
        $after = (int) $request->query('after', 0);

        return $plan->messages()->with('user:id,name')
            ->when($after > 0, fn ($q) => $q->where('id', '>', $after))
            ->latest('id')->limit(100)->get()->reverse()->values()
            ->map(fn (PlanMessage $m) => $this->message($m, $request));
    }

    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $plan = $this->memberPlan($request, $id);
        abort_if($plan->visibility === 'private', 422, 'Chat is for group plans.');
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $message = $plan->messages()->create($data + ['user_id' => $request->user()->id]);

        return response()->json($this->message($message->load('user:id,name'), $request), 201);
    }

    public function notes(Request $request, int $id): Collection
    {
        return $this->memberPlan($request, $id)->notes()->with('user:id,name')
            ->orderByDesc('pinned')->latest('updated_at')->get();
    }

    public function addNote(Request $request, int $id): JsonResponse
    {
        $plan = $this->memberPlan($request, $id);
        $note = $plan->notes()->create($this->validatedNote($request) + ['user_id' => $request->user()->id]);

        return response()->json($note->load('user:id,name'), 201);
    }

    public function updateNote(Request $request, int $id, int $noteId): PlanNote
    {
        $note = $this->memberPlan($request, $id)->notes()->findOrFail($noteId);
        $note->update($this->validatedNote($request, partial: true));

        return $note->load('user:id,name');
    }

    public function deleteNote(Request $request, int $id, int $noteId): Response
    {
        $plan = $this->memberPlan($request, $id);
        $note = $plan->notes()->findOrFail($noteId);
        $this->authorizeAuthorOrOwner($request, $plan, $note->user_id);
        $note->delete();

        return response()->noContent();
    }

    public function events(Request $request, int $id): Collection
    {
        return $this->memberPlan($request, $id)->events()->with('user:id,name')
            ->orderBy('date')->orderBy('time')->get();
    }

    public function addEvent(Request $request, int $id): JsonResponse
    {
        $plan = $this->memberPlan($request, $id);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'date' => ['required', 'date'],
            'time' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $event = $plan->events()->create($data + ['user_id' => $request->user()->id]);

        return response()->json($event->load('user:id,name'), 201);
    }

    public function deleteEvent(Request $request, int $id, int $eventId): Response
    {
        $plan = $this->memberPlan($request, $id);
        $event = $plan->events()->findOrFail($eventId);
        $this->authorizeAuthorOrOwner($request, $plan, $event->user_id);
        $event->delete();

        return response()->noContent();
    }

    /**
     * @return array{id: int, body: string, user_id: int, name: string, mine: bool, created_at: mixed}
     */
    private function message(PlanMessage $m, Request $request): array
    {
        return [
            'id' => $m->id,
            'body' => $m->body,
            'user_id' => $m->user_id,
            'name' => $m->user->name,
            'mine' => $m->user_id === $request->user()->id,
            'created_at' => $m->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedNote(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$req, 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:10000'],
            'pinned' => ['sometimes', 'boolean'],
        ]);
    }

    private function authorizeAuthorOrOwner(Request $request, SharedPlan $plan, int $authorId): void
    {
        abort_unless($authorId === $request->user()->id || $plan->owner_id === $request->user()->id, 403);
    }

    private function memberPlan(Request $request, int $id): SharedPlan
    {
        $plan = SharedPlan::findOrFail($id);
        abort_unless($plan->isMember($request->user()), 404);

        return $plan;
    }
}
