<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SharedPlan;
use App\Models\SharedPlanInvite;
use App\Models\User;
use App\Services\AlertService;
use App\Services\SharedPlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SharedPlanController extends Controller
{
    public function __construct(private SharedPlanService $plans, private AlertService $alerts) {}

    /** My plans plus invitations waiting for me. */
    public function index(Request $request)
    {
        $user = $request->user();
        $plans = $user->sharedPlans()->withCount('members')->latest('shared_plans.updated_at')->get()
            ->map(fn (SharedPlan $p) => array_merge($p->makeHidden('invite_code')->toArray(), ['my_role' => $p->pivot->role, 'summary' => $this->plans->summary($p)]));

        $invites = SharedPlanInvite::with(['plan:id,name,icon,type,owner_id', 'inviter:id,name'])
            ->where('email', strtolower($user->email))->where('status', 'pending')
            ->whereHas('plan', fn ($q) => $q->where('visibility', 'group'))
            ->get();

        return ['plans' => $plans, 'invites' => $invites];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $user = $request->user();

        $plan = DB::transaction(function () use ($data, $user) {
            $plan = new SharedPlan(collect($data)->except('emails')->all() + ['currency' => $user->currency]);
            $plan->owner_id = $user->id;
            $plan->save();
            $plan->members()->attach($user->id, ['role' => 'owner']);

            return $plan;
        });

        if (! empty($data['emails']) && $plan->visibility === 'group') {
            $this->createInvites($plan, $user, $data['emails']);
        }

        return response()->json($this->detail($plan, $user), 201);
    }

    public function show(Request $request, int $id)
    {
        return $this->detail($this->memberPlan($request, $id), $request->user());
    }

    public function update(Request $request, int $id)
    {
        $plan = $this->ownedPlan($request, $id);
        $data = $this->validated($request, partial: true);

        if (($data['visibility'] ?? null) === 'private' && $plan->members()->count() > 1) {
            throw ValidationException::withMessages(['visibility' => 'Remove the other members before making this plan private.']);
        }
        $plan->update(collect($data)->except('emails')->all());
        if ($plan->visibility === 'private') {
            $plan->invites()->where('status', 'pending')->delete();
        }

        return $this->detail($plan, $request->user());
    }

    public function destroy(Request $request, int $id)
    {
        $this->ownedPlan($request, $id)->delete();

        return response()->noContent();
    }

    public function invite(Request $request, int $id)
    {
        $plan = $this->memberPlan($request, $id);
        $this->ensureGroup($plan);
        $data = $request->validate(['emails' => 'required|array|min:1|max:20', 'emails.*' => 'email|max:255']);
        $added = $this->createInvites($plan, $request->user(), $data['emails']);

        return ['invited' => $added, 'plan' => $this->detail($plan, $request->user())];
    }

    public function cancelInvite(Request $request, int $id, int $inviteId)
    {
        $plan = $this->memberPlan($request, $id);
        $plan->invites()->whereKey($inviteId)->delete();

        return response()->noContent();
    }

    public function regenerateCode(Request $request, int $id)
    {
        $plan = $this->ownedPlan($request, $id);
        $plan->regenerateCode();

        return $this->detail($plan, $request->user());
    }

    /** Preview a plan from its invite code before joining (QR scan / typed code). */
    public function preview(Request $request, string $code)
    {
        $plan = $this->planByCode($code);

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'icon' => $plan->icon,
            'type' => $plan->type,
            'description' => $plan->description,
            'target_amount' => $plan->target_amount,
            'target_date' => $plan->target_date?->toDateString(),
            'currency' => $plan->currency,
            'owner' => $plan->owner->name,
            'members_count' => $plan->members()->count(),
            'already_member' => $plan->isMember($request->user()),
        ];
    }

    public function join(Request $request, string $code)
    {
        $plan = $this->planByCode($code);
        $user = $request->user();
        $plan->members()->syncWithoutDetaching([$user->id => ['role' => 'member']]);
        $plan->invites()->where('email', strtolower($user->email))->update(['status' => 'accepted']);
        $plan->touch();

        return $this->detail($plan, $user);
    }

    public function respondInvite(Request $request, int $inviteId)
    {
        $data = $request->validate(['accept' => 'required|boolean']);
        $user = $request->user();
        $invite = SharedPlanInvite::where('email', strtolower($user->email))->where('status', 'pending')->findOrFail($inviteId);

        $invite->update(['status' => $data['accept'] ? 'accepted' : 'declined']);
        if ($data['accept']) {
            $invite->plan->members()->syncWithoutDetaching([$user->id => ['role' => 'member']]);
            $this->alerts->refresh($user);

            return $this->detail($invite->plan, $user);
        }
        $this->alerts->refresh($user);

        return response()->noContent();
    }

    public function leave(Request $request, int $id)
    {
        $plan = $this->memberPlan($request, $id);
        abort_if($plan->isOwner($request->user()), 422, 'The owner cannot leave. Delete the plan or transfer ownership first.');
        $plan->members()->detach($request->user()->id);

        return response()->noContent();
    }

    public function removeMember(Request $request, int $id, int $userId)
    {
        $plan = $this->ownedPlan($request, $id);
        abort_if($userId === $plan->owner_id, 422, 'The owner cannot be removed.');
        $plan->members()->detach($userId);

        return $this->detail($plan, $request->user());
    }

    public function transferOwnership(Request $request, int $id, int $userId)
    {
        $plan = $this->ownedPlan($request, $id);
        abort_unless($plan->members()->whereKey($userId)->exists(), 422, 'That person is not a member.');
        DB::transaction(function () use ($plan, $userId) {
            $plan->members()->updateExistingPivot($plan->owner_id, ['role' => 'member']);
            $plan->members()->updateExistingPivot($userId, ['role' => 'owner']);
            $plan->owner_id = $userId;
            $plan->save();
        });

        return $this->detail($plan, $request->user());
    }

    /** Add money to the pot or record a shared expense. Optionally mirrors it into my own transactions. */
    public function addItem(Request $request, int $id)
    {
        $plan = $this->memberPlan($request, $id);
        $user = $request->user();
        $data = $request->validate([
            'kind' => 'required|in:contribution,expense,settlement',
            'from_user_id' => ['nullable', Rule::in($memberIds = $plan->members()->pluck('users.id')->all())],
            'to_user_id' => ['required_if:kind,settlement', 'nullable', Rule::in($memberIds)],
            'amount' => 'required|numeric|min:0.01|max:100000000',
            'description' => 'nullable|string|max:255',
            'occurred_on' => 'nullable|date',
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('user_id', $user->id)],
            'record_personal' => 'boolean',
        ]);
        $date = $data['occurred_on'] ?? Carbon::today()->toDateString();

        // A settlement can be recorded by either the payer or the receiver.
        $payerId = $user->id;
        if ($data['kind'] === 'settlement') {
            $payerId = (int) ($data['from_user_id'] ?? $user->id);
            if ($payerId === (int) $data['to_user_id'] || ! in_array($user->id, [$payerId, (int) $data['to_user_id']], true)) {
                throw ValidationException::withMessages(['to_user_id' => 'A settlement must be between you and another member.']);
            }
        }

        DB::transaction(function () use ($plan, $user, $data, $date, $payerId) {
            $txId = null;
            if (($data['record_personal'] ?? true) && $data['kind'] !== 'settlement') {
                $categoryId = $data['category_id'] ?? $user->categories()->where('name', $data['kind'] === 'contribution' ? 'Savings' : 'Other')->value('id');
                $txId = $user->transactions()->create([
                    'type' => 'expense',
                    'amount' => $data['amount'],
                    'category_id' => $categoryId,
                    'description' => ($data['kind'] === 'contribution' ? 'Added to ' : '').$plan->name.(! empty($data['description']) ? ': '.$data['description'] : ''),
                    'occurred_on' => $date,
                    'source' => 'manual',
                ])->id;
            }
            $plan->items()->create([
                'user_id' => $payerId,
                'to_user_id' => $data['kind'] === 'settlement' ? $data['to_user_id'] : null,
                'kind' => $data['kind'],
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
                'occurred_on' => $date,
                'transaction_id' => $txId,
            ]);
            $plan->touch();
        });
        $this->alerts->refresh($user->fresh());

        return response()->json($this->detail($plan, $user), 201);
    }

    public function deleteItem(Request $request, int $id, int $itemId)
    {
        $plan = $this->memberPlan($request, $id);
        $item = $plan->items()->findOrFail($itemId);
        abort_unless(in_array($request->user()->id, [$item->user_id, $item->to_user_id], true) || $plan->isOwner($request->user()), 403);

        DB::transaction(function () use ($item) {
            // Remove the mirrored personal transaction so balances stay correct.
            if ($item->transaction_id) {
                User::find($item->user_id)?->transactions()->whereKey($item->transaction_id)->first()?->delete();
            }
            $item->delete();
        });

        return $this->detail($plan, $request->user());
    }

    public function addTask(Request $request, int $id)
    {
        $plan = $this->memberPlan($request, $id);
        $data = $this->validatedTask($request, $plan);
        $plan->tasks()->create($data + ['created_by' => $request->user()->id]);

        return response()->json($this->detail($plan, $request->user()), 201);
    }

    public function updateTask(Request $request, int $id, int $taskId)
    {
        $plan = $this->memberPlan($request, $id);
        $plan->tasks()->findOrFail($taskId)->update($this->validatedTask($request, $plan, partial: true));

        return $this->detail($plan, $request->user());
    }

    public function deleteTask(Request $request, int $id, int $taskId)
    {
        $plan = $this->memberPlan($request, $id);
        $plan->tasks()->findOrFail($taskId)->delete();

        return $this->detail($plan, $request->user());
    }

    // ----------------------------------------------------------------------

    private function detail(SharedPlan $plan, User $user): array
    {
        $plan->refresh();
        $isOwner = $plan->isOwner($user);

        return array_merge($plan->toArray(), [
            'is_owner' => $isOwner,
            'join_url' => $plan->visibility === 'group' ? $plan->joinUrl() : null,
            'invite_code' => $plan->visibility === 'group' ? $plan->invite_code : null,
            'summary' => $this->plans->summary($plan),
            'items' => $plan->items()->with(['user:id,name', 'toUser:id,name'])->latest('occurred_on')->latest('id')->limit(200)->get()
                ->map(fn ($i) => $i->toArray() + ['mine' => in_array($user->id, [$i->user_id, $i->to_user_id], true)]),
            'tasks' => $plan->tasks()->with('assignee:id,name')->orderBy('done')->orderBy('id')->get(),
            'invites' => $plan->invites()->where('status', 'pending')->get(['id', 'email', 'created_at']),
        ]);
    }

    private function createInvites(SharedPlan $plan, User $by, array $emails): int
    {
        $added = 0;
        foreach (array_unique(array_map('strtolower', $emails)) as $email) {
            if ($plan->members()->where('email', $email)->exists()) {
                continue;
            }
            $invite = $plan->invites()->firstOrNew(['email' => $email]);
            $invite->fill(['invited_by' => $by->id, 'status' => 'pending'])->save();
            $added++;
            if ($invitee = User::where('email', $email)->first()) {
                $this->alerts->refresh($invitee);
            }
        }

        return $added;
    }

    private function memberPlan(Request $request, int $id): SharedPlan
    {
        $plan = SharedPlan::findOrFail($id);
        abort_unless($plan->isMember($request->user()), 404);

        return $plan;
    }

    private function ownedPlan(Request $request, int $id): SharedPlan
    {
        $plan = $this->memberPlan($request, $id);
        abort_unless($plan->isOwner($request->user()), 403, 'Only the plan owner can do that.');

        return $plan;
    }

    private function planByCode(string $code): SharedPlan
    {
        $plan = SharedPlan::where('invite_code', strtoupper(trim($code)))->where('visibility', 'group')->first();
        abort_unless($plan, 404, 'That invite code is not valid or has been reset.');

        return $plan;
    }

    private function ensureGroup(SharedPlan $plan): void
    {
        if ($plan->visibility !== 'group') {
            throw ValidationException::withMessages(['visibility' => 'Switch this plan to Group before inviting people.']);
        }
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "$req|string|max:100",
            'icon' => 'nullable|string|max:16',
            'type' => 'nullable|in:outing,goal,household,trip,other',
            'visibility' => "$req|in:private,group",
            'description' => 'nullable|string|max:1000',
            'target_amount' => 'nullable|numeric|min:1|max:100000000',
            'target_date' => 'nullable|date',
            'emails' => 'nullable|array|max:20',
            'emails.*' => 'email|max:255',
        ]);
    }

    private function validatedTask(Request $request, SharedPlan $plan, bool $partial = false): array
    {
        $memberIds = $plan->members()->pluck('users.id')->all();

        return $request->validate([
            'title' => ($partial ? 'sometimes' : 'required').'|string|max:200',
            'assignee_id' => ['nullable', Rule::in($memberIds)],
            'estimated_cost' => 'nullable|numeric|min:0|max:100000000',
            'done' => 'boolean',
        ]);
    }
}
