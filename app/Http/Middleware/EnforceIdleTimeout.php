<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side idle timeout for web staff dashboards.
 *
 * Laravel's session lifetime is sliding: every request (including background
 * polling) renews it. So we track our own "last user activity" timestamp, which
 * is only refreshed by genuine navigations (full-page HTML requests) or by the
 * explicit keep-alive endpoint the browser calls after real user interaction.
 */
class EnforceIdleTimeout
{
    public const SESSION_KEY = 'last_user_activity_at';

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $timeout = (int) config('session.idle_timeout_seconds', 1800);
        $now = now()->timestamp;
        $last = (int) $request->session()->get(self::SESSION_KEY, 0);

        if ($last > 0 && ($now - $last) >= $timeout) {
            foreach (['web', 'superadmin', 'dispatcher', 'teamleader', 'systemadmin'] as $guard) {
                Auth::guard($guard)->logout();
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => 'Your session ended due to inactivity.',
                    'redirect' => route('login'),
                ], 401);
            }

            return redirect()->route('login')
                ->withErrors(['auth' => 'You were signed out due to inactivity. Please sign in again.']);
        }

        if ($last === 0 || $mode === 'touch' || $this->isUserNavigation($request)) {
            $request->session()->put(self::SESSION_KEY, $now);
        }

        return $next($request);
    }

    /** Full-page HTML requests are user-driven; fetch/XHR polling is not. */
    private function isUserNavigation(Request $request): bool
    {
        return ! $request->ajax()
            && ! $request->expectsJson()
            && str_contains((string) $request->header('Accept', ''), 'text/html');
    }
}
