<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BudgetGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'currency' => 'sometimes|string|size:3',
            'monthly_income' => 'sometimes|numeric|min:0|max:100000000',
            'pay_frequency' => 'sometimes|in:weekly,biweekly,semimonthly,monthly',
            'next_payday' => 'sometimes|nullable|date',
            'current_balance' => 'sometimes|numeric|min:-100000000|max:100000000',
            'alert_threshold' => 'sometimes|numeric|min:50|max:100',
            'onboarded' => 'sometimes|boolean',
            'payment_handle' => 'sometimes|nullable|string|max:120',
        ]);
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $request->user()->update($data);

        return $request->user()->fresh();
    }

    /** Finish onboarding: save income/payday settings and generate the first budget. */
    public function onboard(Request $request, BudgetGenerator $generator)
    {
        $this->update($request);
        $user = $request->user()->fresh();
        $user->update(['onboarded' => true]);

        return ['user' => $user, 'budget' => $generator->generate($user)];
    }

    public function destroy(Request $request)
    {
        $request->validate(['password' => 'required|string']);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }
        Storage::disk('local')->deleteDirectory('receipts/'.$request->user()->id);
        $request->user()->tokens()->delete();
        $request->user()->delete();

        return response()->noContent();
    }
}
