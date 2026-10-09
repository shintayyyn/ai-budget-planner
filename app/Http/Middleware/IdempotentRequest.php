<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Changes made offline are queued on the device and replayed when it is back
 * online. Each carries an Idempotency-Key, so a replay that already reached
 * the server (e.g. the connection dropped before the reply) is not applied twice.
 */
class IdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();
        if (! $key || ! $user || $request->isMethodSafe() || strlen($key) > 64) {
            return $next($request);
        }

        $seen = DB::table('sync_receipts')->where('user_id', $user->id)->where('key', $key)->first();
        if ($seen) {
            return response($seen->body, $seen->status, ['Content-Type' => 'application/json', 'Idempotent-Replay' => 'true']);
        }

        $response = $next($request);
        if ($response->getStatusCode() < 500 && $response->getStatusCode() !== 429) {
            DB::table('sync_receipts')->insertOrIgnore([
                'user_id' => $user->id,
                'key' => $key,
                'status' => $response->getStatusCode(),
                'body' => $response->getContent(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $response;
    }
}
