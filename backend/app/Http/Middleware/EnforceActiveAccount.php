<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // Fresh database lookup is necessary to enforce mid-session changes.
        // This covers inactive, deleted, demoted and password-reset accounts.
        $current = $user->fresh();

        if (! $current || ! $current->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Your account is inactive or unavailable. Please sign in again.',
            ], 401);
        }

        // Refresh the guard so downstream role checks see the latest role.
        Auth::setUser($current);

        $fingerprint = $request->session()->get('stayledger_password_fingerprint');
        if (is_string($fingerprint)) {
            $expected = hash_hmac(
                'sha256',
                (string) $current->getAuthPassword(),
                (string) config('app.key')
            );

            if (! hash_equals($fingerprint, $expected)) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json([
                    'message' => 'Your login session has expired. Please sign in again.',
                ], 401);
            }
        }

        return $next($request);
    }
}
