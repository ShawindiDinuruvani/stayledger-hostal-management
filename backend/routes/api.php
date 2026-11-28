<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MonthlyFeeController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\RoomAllocationController;
use App\Http\Controllers\SharedKitchenController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Middleware\EnsureAuthenticated;

// Monthly Fees Module
Route::prefix('fees')->middleware([EnsureAuthenticated::class])->group(function () {
    Route::get('/', [MonthlyFeeController::class, 'index']);
    Route::get('/preview', [MonthlyFeeController::class, 'preview']);
    Route::post('/generate', [MonthlyFeeController::class, 'generate']);
    Route::get('/{id}', [MonthlyFeeController::class, 'show'])->whereNumber('id');
});

// Payments Module
Route::middleware([EnsureAuthenticated::class])->group(function () {
    Route::get('/payments', [PaymentController::class, 'history']);
    Route::post('/payments/{id}/reverse', [PaymentController::class, 'reverse'])->whereNumber('id');
    Route::post('/payments/{id}/correct', [PaymentController::class, 'correct'])->whereNumber('id');
    Route::post('/fees/{id}/payments', [PaymentController::class, 'record'])->whereNumber('id');
    Route::get('/fees/{id}/payments', [PaymentController::class, 'forFee'])->whereNumber('id');
    Route::get('/fees/{id}/balance', [PaymentController::class, 'balance'])->whereNumber('id');
});

// Expenses Module
Route::get('/expenses', [ExpenseController::class, 'index']);
Route::post('/expenses', [ExpenseController::class, 'store']);

// Room Allocations Module
Route::get('/allocations/dashboard', [RoomAllocationController::class, 'dashboard']);
Route::post('/allocations', [RoomAllocationController::class, 'store']);
Route::post('/allocations/{id}/transfer', [RoomAllocationController::class, 'transfer'])->whereNumber('id');
Route::post('/allocations/{id}/checkout', [RoomAllocationController::class, 'checkout'])->whereNumber('id');

// Shared Kitchen Module
Route::get('/kitchen', [SharedKitchenController::class, 'index']);