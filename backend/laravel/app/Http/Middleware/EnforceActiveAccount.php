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

        // Guests must still be handled by the normal 'auth' middleware.
        if ($user && ! $user->is_active) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Your account is inactive. Contact an administrator.',
            ], 401);
        }

        if ($user) {
            $fingerprint = $request->session()->get('stayledger_password_fingerprint');
            if (is_string($fingerprint)) {
                $expected = hash_hmac(
                    'sha256',
                    (string) $user->getAuthPassword(),
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
        }

        return $next($request);
    }
}
