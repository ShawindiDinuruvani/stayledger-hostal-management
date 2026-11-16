<?php
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordController;

Route::get('/', function () {
    return view('welcome');
});

// Login
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

// Protected routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/account/password', [PasswordController::class, 'update'])->middleware('throttle:5,1');
});
// Admin-only User Management
Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
});
// Used by the Vite frontend for same-origin CSRF protection.
Route::get('/csrf-token', fn () => response()->json(['csrf_token' => csrf_token()]));
