<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AlertService;
use App\Services\BudgetGenerator;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
    public function __construct(private FinanceService $finance, private BudgetGenerator $generator, private AlertService $alerts) {}

    public function show(Request $request)
    {
        return $this->finance->monthSummary($request->user(), $this->month($request));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'month' => 'required|date_format:Y-m',
            'lines' => 'required|array',
            'lines.*.category_id' => ['required', Rule::exists('categories', 'id')->where('user_id', $request->user()->id)],
            'lines.*.amount' => 'required|numeric|min:0|max:100000000',
        ]);
        $month = Carbon::createFromFormat('Y-m-d', $data['month'].'-01')->toDateString();
        foreach ($data['lines'] as $line) {
            $request->user()->budgets()->updateOrCreate(['category_id' => $line['category_id'], 'month' => $month], ['amount' => $line['amount']]);
        }
        $this->alerts->refresh($request->user());

        return $this->finance->monthSummary($request->user(), Carbon::parse($month));
    }

    /** Preview (?save=0) or apply a freshly generated personalised budget. */
    public function generate(Request $request)
    {
        $result = $this->generator->generate($request->user(), $this->month($request), $request->boolean('save', true));
        $this->alerts->refresh($request->user());

        return $result;
    }

    private function month(Request $request): Carbon
    {
        $request->validate(['month' => 'nullable|date_format:Y-m']);
        $m = $request->input('month');

        return $m ? Carbon::createFromFormat('Y-m-d', "$m-01") : Carbon::today()->startOfMonth();
    }
}
