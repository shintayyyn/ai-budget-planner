<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AlertService;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function __construct(private FinanceService $finance, private AlertService $alerts) {}

    public function index(Request $request)
    {
        $request->validate(['month' => 'nullable|date_format:Y-m', 'category_id' => 'nullable|integer', 'type' => 'nullable|in:expense,income', 'q' => 'nullable|string|max:100']);

        $query = $request->user()->transactions()->with('category:id,name,icon,color')
            ->orderByDesc('occurred_on')->orderByDesc('id');

        if ($month = $request->input('month')) {
            $query->where('occurred_on', 'like', "$month-%");
        }
        if ($cat = $request->input('category_id')) {
            $query->where('category_id', $cat);
        }
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }
        if ($q = $request->input('q')) {
            $query->where(fn ($w) => $w->where('description', 'like', "%$q%")->orWhere('merchant', 'like', "%$q%"));
        }

        return $query->paginate(min(100, (int) $request->input('per_page', 30)));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if (empty($data['category_id'])) {
            $data['category_id'] = $this->finance->guessCategory($request->user(), trim(($data['description'] ?? '').' '.($data['merchant'] ?? '')), $data['type'])?->id;
        }
        $tx = $request->user()->transactions()->create($data);
        $this->alerts->refresh($request->user()->fresh());

        return response()->json($tx->load('category:id,name,icon,color'), 201);
    }

    public function update(Request $request, int $id)
    {
        $tx = $request->user()->transactions()->findOrFail($id);
        $tx->update($this->validated($request));
        $this->alerts->refresh($request->user()->fresh());

        return $tx->load('category:id,name,icon,color');
    }

    public function destroy(Request $request, int $id)
    {
        $tx = $request->user()->transactions()->findOrFail($id);
        if ($tx->receipt_path) {
            Storage::disk('local')->delete($tx->receipt_path);
        }
        $tx->delete();
        $this->alerts->refresh($request->user()->fresh());

        return response()->noContent();
    }

    /** Suggest a category for free text (used by forms before saving). */
    public function categorize(Request $request)
    {
        $data = $request->validate(['text' => 'required|string|max:255', 'type' => 'nullable|in:expense,income']);

        return $this->finance->guessCategory($request->user(), $data['text'], $data['type'] ?? 'expense');
    }

    /** Store a receipt photo. OCR happens on the device; only the image is kept here. */
    public function uploadReceipt(Request $request)
    {
        $request->validate(['receipt' => 'required|image|max:8192']);
        $path = $request->file('receipt')->store('receipts/'.$request->user()->id, 'local');

        return ['receipt_path' => $path];
    }

    public function receipt(Request $request, int $id)
    {
        $tx = $request->user()->transactions()->findOrFail($id);
        abort_unless($tx->receipt_path && Storage::disk('local')->exists($tx->receipt_path), 404);

        return Storage::disk('local')->response($tx->receipt_path);
    }

    private function validated(Request $request): array
    {
        $uid = $request->user()->id;

        return $request->validate([
            'type' => 'required|in:expense,income',
            'amount' => 'required|numeric|min:0.01|max:100000000',
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('user_id', $uid)],
            'description' => 'nullable|string|max:255',
            'merchant' => 'nullable|string|max:255',
            'occurred_on' => 'required|date',
            'source' => 'nullable|in:manual,chat,receipt,bill',
            'receipt_path' => ['nullable', 'string', 'starts_with:receipts/'.$uid.'/'],
        ]) + ['source' => 'manual'];
    }
}
