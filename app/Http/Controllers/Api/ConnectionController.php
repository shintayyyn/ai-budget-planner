<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Personal QR codes: preview a code, connect as buddies, list and remove buddies. */
class ConnectionController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->buddies()->orderBy('name')->get(['users.id', 'users.name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'since' => $u->pivot->created_at?->toDateString()]);
    }

    public function preview(Request $request, string $code)
    {
        $friend = $this->byCode($code);
        $me = $request->user();

        return [
            'name' => $friend->name,
            'is_self' => $friend->id === $me->id,
            'already_connected' => $me->buddies()->whereKey($friend->id)->exists(),
        ];
    }

    public function connect(Request $request, string $code)
    {
        $friend = $this->byCode($code);
        $me = $request->user();
        abort_if($friend->id === $me->id, 422, "That's your own code.");

        DB::transaction(function () use ($me, $friend) {
            $me->buddies()->syncWithoutDetaching([$friend->id]);
            $friend->buddies()->syncWithoutDetaching([$me->id]);
        });

        return response()->json(['id' => $friend->id, 'name' => $friend->name], 201);
    }

    public function destroy(Request $request, int $userId)
    {
        $me = $request->user();
        $me->buddies()->detach($userId);
        User::find($userId)?->buddies()->detach($me->id);

        return response()->noContent();
    }

    private function byCode(string $code): User
    {
        $friend = User::where('share_code', User::normalizeShareCode($code))->first();
        abort_unless($friend, 404, 'That Amotan code is not valid or has been reset.');

        return $friend;
    }
}
