<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:sanctum. Token revocation on deactivation is the primary
 * control (see User::booted()); this re-checks the account's CURRENT state on
 * every API request so a missed revocation path, a race, or a manually kept
 * token can never keep a disabled account working. The response is the same
 * generic 401 for every disabled state, so the reason (archive, pending
 * deletion, ...) is never exposed, and 401 makes the mobile app sign out.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->canUseApi()) {
            // Don't let the stale token linger for another attempt.
            $token = $user->currentAccessToken();
            if ($token && method_exists($token, 'delete')) {
                $token->delete();
            }

            return response()->json([
                'success' => false,
                'message' => 'Account is inactive. Please contact support.',
            ], 401);
        }

        return $next($request);
    }
}
