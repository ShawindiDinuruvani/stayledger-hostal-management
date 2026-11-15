<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticated
{
    /**
     * Handle an incoming request.
     * Enforces the architectural authentication boundary for Monthly Fee Management.
     *
     * In accordance with project instructions:
     * - No runtime authentication bypass or arbitrary tokens are accepted.
     * - Established authentication (e.g. Laravel Auth guard / session / Sanctum / actingAs in tests) is reused when present.
     * - In the absence of an integrated auth module from teammates, protected operations remain DENIED (HTTP 401).
     * - Users & Access module is owned by teammate A.K.S. Athukorala.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check if authenticated via established Laravel Auth provider / guard (or test actingAs)
        if ($request->user()) {
            return $next($request);
        }

        // 2. Runtime / Browser / Unauthenticated: Deny access with explicit missing integration status
        return response()->json([
            'message' => 'Unauthorized. Access restricted to authorized hostel management accounts.',
            'integration_status' => 'AUTH_INTEGRATION_PENDING',
        ], 401);
    }
}
