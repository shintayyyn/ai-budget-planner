<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AlertService;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __invoke(Request $request, FinanceService $finance, AlertService $alerts)
    {
        $user = $request->user();
        $alerts->refresh($user);

        $start = Carbon::today()->startOfMonth()->subMonthsNoOverflow(5);
        $trend = $user->transactions()
            ->where('occurred_on', '>=', $start->toDateString())
            ->selectRaw("DATE_FORMAT(occurred_on, '%Y-%m') as month, type, SUM(amount) as total")
            ->groupBy('month', 'type')->get()
            ->groupBy('month');
        $months = collect(range(0, 5))->map(function ($i) use ($start, $trend) {
            $m = $start->copy()->addMonthsNoOverflow($i)->format('Y-m');
            $rows = $trend->get($m, collect());

            return [
                'month' => $m,
                'income' => round((float) $rows->firstWhere('type', 'income')?->total, 2),
                'expense' => round((float) $rows->firstWhere('type', 'expense')?->total, 2),
            ];
        });

        return [
            'user' => $user->fresh(),
            'safe_to_spend' => $finance->safeToSpend($user),
            'month' => $finance->monthSummary($user),
            'trend' => $months,
            'recent' => $user->transactions()->with('category:id,name,icon,color')->latest('occurred_on')->latest('id')->limit(6)->get(),
            'goals' => $user->savingsGoals()->orderBy('priority')->limit(3)->get()->map(fn ($g) => $g->toArray() + ['projection' => $finance->goalProjection($g)]),
            'alerts' => $user->alerts()->whereNull('read_at')->orderByRaw("FIELD(level, 'danger', 'warning', 'info')")->limit(3)->get(),
            'unread_alerts' => $user->alerts()->whereNull('read_at')->count(),
        ];
    }
}
