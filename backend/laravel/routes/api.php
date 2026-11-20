<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MonthlyFeeController;
use App\Http\Middleware\EnsureAuthenticated;

/*
|--------------------------------------------------------------------------
| StayLedger API Routes - Monthly Fee Management Module
|--------------------------------------------------------------------------
| Module Owner: S. Dinuruvani (Student ID: 36750)
| University Module: CS304.3 Advanced Database Management Systems
*/

Route::prefix('fees')->middleware([EnsureAuthenticated::class])->group(function () {
    Route::get('/', [MonthlyFeeController::class, 'index']);
    Route::get('/preview', [MonthlyFeeController::class, 'preview']);
    Route::post('/generate', [MonthlyFeeController::class, 'generate']);
    Route::get('/{id}', [MonthlyFeeController::class, 'show'])->whereNumber('id');
});
