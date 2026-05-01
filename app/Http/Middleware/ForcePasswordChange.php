<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /**
     * Routes that are always accessible, even when a password change is required.
     * This prevents redirect loops and allows logout to work.
     */
    private const EXEMPT_ROUTE_NAMES = [
        'password.force-change',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            // Allow the force-change page itself and logout to pass through.
            $routeName = $request->route()?->getName();
            if (in_array($routeName, self::EXEMPT_ROUTE_NAMES, true)) {
                return $next($request);
            }

            // AJAX / JSON requests: return 403 so the frontend can redirect.
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'You must change your password before continuing.',
                    'redirect' => route('password.force-change'),
                ], 403);
            }

            return redirect()->route('password.force-change');
        }

        return $next($request);
    }
}
