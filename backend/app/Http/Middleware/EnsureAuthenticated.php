<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureAuthenticated
{
    /**
     * Verify the User Accounts Laravel session, without accepting unsigned
     * identities or client-provided role headers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Preserve Laravel's own authenticated guards for team integrations
        // and existing tests. Anonymous API clients must pass central auth.
        if ($request->user()) {
            return $next($request);
        }

        $cookie = $request->header('Cookie', '');
        if (!is_string($cookie) || $cookie === '') {
            return $this->deny(401, 'Sign in to StayLedger before accessing monthly fees.');
        }

        $isWrite = !in_array(strtoupper($request->method()), ['GET', 'HEAD'], true);
        $csrf = $request->header('X-CSRF-TOKEN', '');
        if ($isWrite && (!is_string($csrf) || $csrf === '')) {
            return $this->deny(419, 'CSRF token required for fee changes.');
        }

        // Fixed server configuration, never a URL supplied by the browser.
        $base = rtrim((string) env('STAYLEDGER_AUTH_URL', 'http://127.0.0.1:8002'), '/');
        $url = $base . ($isWrite ? '/integration/verify' : '/me');

        try {
            $http = Http::timeout(5)->connectTimeout(2)->acceptJson()
                ->withHeaders(['Cookie' => str_replace(["\r", "\n"], '', $cookie)]);
            if ($isWrite) {
                $http = $http->withHeaders(['X-CSRF-TOKEN' => (string) $csrf]);
            }
            $check = $isWrite ? $http->post($url, []) : $http->get($url);
        } catch (Throwable $exception) {
            return $this->deny(503, 'Identity service is unavailable.');
        }

        if ($check->status() === 419) {
            return $this->deny(419, 'Session verification failed. Refresh and retry.');
        }
        if (!$check->successful()) {
            return $this->deny(401, 'Login session is invalid or expired.');
        }

        $user = $check->json('user');
        if (!is_array($user) || !is_numeric($user['id'] ?? null)
            || !in_array($user['role'] ?? null, ['admin', 'staff'], true)
            || ($user['is_active'] ?? false) !== true) {
            return $this->deny(401, 'An active StayLedger account is required.');
        }

        // Server-validated identity; never rely on headers from the client.
        $request->attributes->set('stayledger_user', $user);
        return $next($request);
    }

    private function deny(int $code, string $message): Response
    {
        return response()->json(['message' => $message], $code)
            ->header('Cache-Control', 'no-store');
    }
}
