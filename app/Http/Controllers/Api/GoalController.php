<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AlertService;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class GoalController extends Controller
{
    public function __construct(private FinanceService $finance, private AlertService $alerts) {}

    public function index(Request $request)
    {
        return $request->user()->savingsGoals()->orderBy('priority')->orderBy('target_date')->get()
            ->map(fn ($g) => $g->toArray() + ['projection' => $this->finance->goalProjection($g)]);
    }

    public function store(Request $request)
    {
        $goal = $request->user()->savingsGoals()->create($this->validated($request));

        return response()->json($goal->toArray() + ['projection' => $this->finance->goalProjection($goal)], 201);
    }

    public function update(Request $request, int $id)
    {
        $goal = $request->user()->savingsGoals()->findOrFail($id);
        $goal->update($this->validated($request));

        return $goal->toArray() + ['projection' => $this->finance->goalProjection($goal)];
    }

    public function destroy(Request $request, int $id)
    {
        $request->user()->savingsGoals()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /** Move money into a goal. Positive amounts leave the spending balance; negative ones withdraw. */
    public function contribute(Request $request, int $id)
    {
        $user = $request->user();
        $goal = $user->savingsGoals()->findOrFail($id);
        $data = $request->validate(['amount' => 'required|numeric|not_in:0|min:-100000000|max:100000000']);
        $amount = round((float) $data['amount'], 2);
        $amount = max($amount, -$goal->saved_amount);

        $goal->contributions()->create(['amount' => $amount, 'contributed_on' => Carbon::today()]);
        $goal->increment('saved_amount', $amount);

        $user->transactions()->create([
            'type' => $amount > 0 ? 'expense' : 'income',
            'amount' => abs($amount),
            'category_id' => $user->categories()->where('name', $amount > 0 ? 'Savings' : 'Other Income')->value('id'),
            'description' => ($amount > 0 ? 'Saved to ' : 'Withdrew from ')."{$goal->name}",
            'occurred_on' => Carbon::today(),
            'source' => 'manual',
        ]);
        $this->alerts->refresh($user->fresh());
        $goal->refresh();

        return $goal->toArray() + ['projection' => $this->finance->goalProjection($goal)];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'icon' => 'nullable|string|max:16',
            'target_amount' => 'required|numeric|min:1|max:100000000',
            'saved_amount' => 'nullable|numeric|min:0|max:100000000',
            'target_date' => 'nullable|date|after:today',
            'monthly_contribution' => 'nullable|numeric|min:0|max:100000000',
            'priority' => 'nullable|integer|between:1,3',
        ]);
    }
}
