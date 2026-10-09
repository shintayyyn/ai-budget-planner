<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * The assistant's language model runs on the user's device (WebLLM in the
 * browser). The server only supplies the grounded financial facts. A
 * self-hosted Ollama model can optionally be enabled with AI_SERVER_DRIVER=ollama.
 */
class AiController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function context(Request $request)
    {
        return $this->finance->snapshot($request->user());
    }

    public function affordability(Request $request)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:100000000', 'monthly' => 'boolean']);

        return $this->finance->affordability($request->user(), (float) $data['amount'], (bool) ($data['monthly'] ?? false));
    }

    public function status()
    {
        $driver = config('services.ai.server_driver');
        $ollama = false;
        if ($driver === 'ollama') {
            try {
                $ollama = Http::timeout(2)->get(rtrim(config('services.ai.ollama_url'), '/').'/api/tags')->successful();
            } catch (\Throwable) {
                $ollama = false;
            }
        }

        return ['server_driver' => $driver, 'ollama_available' => $ollama, 'ollama_model' => $driver === 'ollama' ? config('services.ai.ollama_model') : null];
    }

    /** Optional: relay a chat to a self-hosted Ollama model on the same machine/network. */
    public function chat(Request $request)
    {
        abort_unless(config('services.ai.server_driver') === 'ollama', 404, 'Server-side AI is disabled. The assistant runs on your device.');
        $data = $request->validate([
            'messages' => 'required|array|max:40',
            'messages.*.role' => 'required|in:system,user,assistant',
            'messages.*.content' => 'required|string|max:20000',
            'json' => 'boolean',
        ]);

        $response = Http::timeout(120)->post(rtrim(config('services.ai.ollama_url'), '/').'/api/chat', array_filter([
            'model' => config('services.ai.ollama_model'),
            'messages' => $data['messages'],
            'stream' => false,
            'format' => ! empty($data['json']) ? 'json' : null,
            'options' => ['temperature' => 0.3],
        ]));

        abort_unless($response->successful(), 502, 'The local Ollama model did not respond.');

        return ['content' => $response->json('message.content', '')];
    }
}
