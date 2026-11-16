<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Support mixed-case input without guessing between legacy duplicate accounts.
        $email = Str::lower(trim($credentials['email']));
        $matches = User::query()->whereRaw('LOWER(email) = ?', [$email])
            ->limit(2)->pluck('email');

        if ($matches->count() !== 1) {
            return response()->json([
                'message' => 'Invalid credentials or inactive account.',
            ], 401);
        }

        $credentials['email'] = $matches->first();

        if (!Auth::attempt([
            ...$credentials,
            'is_active' => true,
        ])) {
            return response()->json([
                'message' => 'Invalid credentials or inactive account.',
            ], 401);
        }

        $request->session()->regenerate();

        // New login sessions carry an HMAC of the current password hash.
        // This lets the middleware revoke sessions after admin password resets.
        $request->session()->put(
            'stayledger_password_fingerprint',
            hash_hmac('sha256', (string) $request->user()->getAuthPassword(), (string) config('app.key'))
        );

        return response()->json([
            'message' => 'Login successful.',
            'user' => $request->user()->only(['id', 'name', 'email', 'role', 'is_active']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->only(['id', 'name', 'email', 'role', 'is_active']),
        ]);
    }
}
