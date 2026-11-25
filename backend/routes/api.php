<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MonthlyFeeController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\RoomAllocationController;
use App\Http\Controllers\SharedKitchenController;
use App\Http\Middleware\EnsureAuthenticated;

// Monthly Fees Module
Route::prefix('fees')->middleware([EnsureAuthenticated::class])->group(function () {
    Route::get('/', [MonthlyFeeController::class, 'index']);
    Route::get('/preview', [MonthlyFeeController::class, 'preview']);
    Route::post('/generate', [MonthlyFeeController::class, 'generate']);
    Route::get('/{id}', [MonthlyFeeController::class, 'show'])->whereNumber('id');
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