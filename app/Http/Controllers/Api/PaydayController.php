<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaydayPlanner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PaydayController extends Controller
{
    public function __construct(private PaydayPlanner $planner) {}

    public function preview(Request $request)
    {
        return $this->run($request, false);
    }

    public function store(Request $request)
    {
        return response()->json($this->run($request, true), 201);
    }

    public function history(Request $request)
    {
        return $request->user()->paydayPlans()->latest('payday')->limit(12)->get();
    }

    private function run(Request $request, bool $save): array
    {
        $data = $request->validate([
            'income' => 'nullable|numeric|min:0|max:100000000',
            'payday' => 'nullable|date',
            'next_payday' => 'nullable|date|after:payday',
        ]);

        return $this->planner->plan(
            $request->user(),
            isset($data['income']) ? (float) $data['income'] : null,
            isset($data['payday']) ? Carbon::parse($data['payday']) : null,
            isset($data['next_payday']) ? Carbon::parse($data['next_payday']) : null,
            $save,
        );
    }
}
