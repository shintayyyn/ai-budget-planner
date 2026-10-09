<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AlertService;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class BillController extends Controller
{
    public function __construct(private FinanceService $finance, private AlertService $alerts) {}

    public function index(Request $request)
    {
        $today = Carbon::today();

        return $request->user()->bills()->with('category:id,name,icon')->orderBy('due_day')->get()
            ->map(fn ($b) => $b->toArray() + ['next_due' => $b->nextDueDate($today)->toDateString(), 'paid_this_cycle' => $b->isPaidFor($b->nextDueDate($today))]);
    }

    public function store(Request $request)
    {
        $bill = $request->user()->bills()->create($this->validated($request));
        $this->alerts->refresh($request->user());

        return response()->json($bill, 201);
    }

    public function update(Request $request, int $id)
    {
        $bill = $request->user()->bills()->findOrFail($id);
        $bill->update($this->validated($request));
        $this->alerts->refresh($request->user());

        return $bill;
    }

    public function destroy(Request $request, int $id)
    {
        $request->user()->bills()->findOrFail($id)->delete();
        $this->alerts->refresh($request->user());

        return response()->noContent();
    }

    /** Record a bill payment: logs the expense, marks the cycle paid and reduces any debt balance. */
    public function pay(Request $request, int $id)
    {
        $user = $request->user();
        $bill = $user->bills()->findOrFail($id);
        $data = $request->validate(['amount' => 'nullable|numeric|min:0.01', 'paid_on' => 'nullable|date']);
        $amount = (float) ($data['amount'] ?? $bill->amount);
        $paidOn = $data['paid_on'] ?? Carbon::today()->toDateString();

        $categoryId = $bill->category_id
            ?? $user->categories()->where('name', $bill->is_debt ? 'Debt Payments' : 'Utilities')->value('id');

        $user->transactions()->create([
            'type' => 'expense', 'amount' => $amount, 'category_id' => $categoryId,
            'description' => $bill->name, 'occurred_on' => $paidOn, 'source' => 'bill',
        ]);

        $bill->last_paid_on = $paidOn;
        if ($bill->is_debt && $bill->debt_balance !== null) {
            $bill->debt_balance = max(0, round($bill->debt_balance - $amount, 2));
        }
        $bill->save();
        $this->alerts->refresh($user->fresh());

        return $bill;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0|max:100000000',
            'due_day' => 'required|integer|between:1,31',
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('user_id', $request->user()->id)],
            'is_debt' => 'boolean',
            'debt_balance' => 'nullable|numeric|min:0',
            'interest_rate' => 'nullable|numeric|min:0|max:100',
        ]);
    }
}
