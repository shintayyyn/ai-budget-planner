<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\PaydayController;
use App\Http\Controllers\Api\PlanSpaceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SharedPlanController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'idempotent'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::post('/onboard', [ProfileController::class, 'onboard']);
    Route::delete('/profile', [ProfileController::class, 'destroy']);
    Route::post('/profile/share-code', [ProfileController::class, 'regenerateShareCode']);

    // Personal QR codes and buddies.
    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::delete('/connections/{userId}', [ConnectionController::class, 'destroy'])->whereNumber('userId');
    Route::get('/connect/{code}', [ConnectionController::class, 'preview'])->middleware('throttle:30,1');
    Route::post('/connect/{code}', [ConnectionController::class, 'connect'])->middleware('throttle:20,1');

    Route::get('/dashboard', DashboardController::class);

    Route::apiResource('categories', CategoryController::class)->except('show');

    Route::post('/transactions/categorize', [TransactionController::class, 'categorize']);
    Route::post('/transactions/receipt', [TransactionController::class, 'uploadReceipt']);
    Route::get('/transactions/{id}/receipt', [TransactionController::class, 'receipt']);
    Route::apiResource('transactions', TransactionController::class)->except('show');

    Route::get('/budget', [BudgetController::class, 'show']);
    Route::put('/budget', [BudgetController::class, 'update']);
    Route::post('/budget/generate', [BudgetController::class, 'generate']);

    Route::post('/bills/{id}/pay', [BillController::class, 'pay']);
    Route::apiResource('bills', BillController::class)->except('show');

    Route::post('/goals/{id}/contribute', [GoalController::class, 'contribute']);
    Route::apiResource('goals', GoalController::class)->except('show');

    Route::get('/alerts', [AlertController::class, 'index']);
    Route::post('/alerts/read-all', [AlertController::class, 'readAll']);
    Route::post('/alerts/{id}/read', [AlertController::class, 'read']);

    Route::get('/payday', [PaydayController::class, 'preview']);
    Route::post('/payday', [PaydayController::class, 'store']);
    Route::get('/payday/history', [PaydayController::class, 'history']);

    // Shared plans: private or group goals/outings managed together.
    Route::get('/plans', [SharedPlanController::class, 'index']);
    Route::post('/plans', [SharedPlanController::class, 'store']);
    Route::get('/plans/{id}', [SharedPlanController::class, 'show'])->whereNumber('id');
    Route::patch('/plans/{id}', [SharedPlanController::class, 'update']);
    Route::delete('/plans/{id}', [SharedPlanController::class, 'destroy']);
    Route::post('/plans/{id}/invites', [SharedPlanController::class, 'invite']);
    Route::delete('/plans/{id}/invites/{inviteId}', [SharedPlanController::class, 'cancelInvite']);
    Route::post('/plans/{id}/code', [SharedPlanController::class, 'regenerateCode']);
    Route::patch('/plans/{id}/me', [SharedPlanController::class, 'updateMe']);
    Route::post('/plans/{id}/leave', [SharedPlanController::class, 'leave']);
    Route::delete('/plans/{id}/members/{userId}', [SharedPlanController::class, 'removeMember']);
    Route::post('/plans/{id}/members/{userId}/owner', [SharedPlanController::class, 'transferOwnership']);
    Route::post('/plans/{id}/items', [SharedPlanController::class, 'addItem']);
    Route::delete('/plans/{id}/items/{itemId}', [SharedPlanController::class, 'deleteItem']);
    Route::post('/plans/{id}/tasks', [SharedPlanController::class, 'addTask']);
    Route::patch('/plans/{id}/tasks/{taskId}', [SharedPlanController::class, 'updateTask']);
    Route::delete('/plans/{id}/tasks/{taskId}', [SharedPlanController::class, 'deleteTask']);

    Route::get('/plans/{id}/messages', [PlanSpaceController::class, 'messages']);
    Route::post('/plans/{id}/messages', [PlanSpaceController::class, 'sendMessage']);
    Route::get('/plans/{id}/notes', [PlanSpaceController::class, 'notes']);
    Route::post('/plans/{id}/notes', [PlanSpaceController::class, 'addNote']);
    Route::patch('/plans/{id}/notes/{noteId}', [PlanSpaceController::class, 'updateNote']);
    Route::delete('/plans/{id}/notes/{noteId}', [PlanSpaceController::class, 'deleteNote']);
    Route::get('/plans/{id}/events', [PlanSpaceController::class, 'events']);
    Route::post('/plans/{id}/events', [PlanSpaceController::class, 'addEvent']);
    Route::delete('/plans/{id}/events/{eventId}', [PlanSpaceController::class, 'deleteEvent']);
    Route::post('/invites/{inviteId}', [SharedPlanController::class, 'respondInvite']);
    Route::get('/join/{code}', [SharedPlanController::class, 'preview'])->middleware('throttle:30,1');
    Route::post('/join/{code}', [SharedPlanController::class, 'join'])->middleware('throttle:10,1');

    Route::get('/ai/context', [AiController::class, 'context']);
    Route::post('/ai/affordability', [AiController::class, 'affordability']);
    Route::get('/ai/status', [AiController::class, 'status']);
    Route::post('/ai/chat', [AiController::class, 'chat'])->middleware('throttle:30,1');
});
