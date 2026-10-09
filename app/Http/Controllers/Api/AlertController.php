<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AlertService;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request, AlertService $alerts)
    {
        $alerts->refresh($request->user());

        return $request->user()->alerts()
            ->orderByRaw('read_at IS NOT NULL')
            ->orderByRaw("FIELD(level, 'danger', 'warning', 'info')")
            ->latest('updated_at')->limit(50)->get();
    }

    public function read(Request $request, int $id)
    {
        $alert = $request->user()->alerts()->findOrFail($id);
        $alert->update(['read_at' => now()]);

        return $alert;
    }

    public function readAll(Request $request)
    {
        $request->user()->alerts()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    }
}
