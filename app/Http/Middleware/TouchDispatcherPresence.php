<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a dispatcher's "online" cache flag alive while they're actually using
 * the panel. Ordinary page navigation acts as the heartbeat (dispatchers use
 * a web session, not a mobile app, so there's no separate ping endpoint like
 * Team Leaders have via TeamLeaderAvailabilityService). A short, refreshed
 * TTL means the flag decays quickly once a dispatcher goes idle or closes
 * the tab, instead of lingering for hours after a one-shot write at login.
 */
class TouchDispatcherPresence
{
    protected int $presenceTtlSeconds = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (int) $user->role_id === 2) {
            Cache::put('dispatcher:presence:' . $user->id, now()->timestamp, $this->presenceTtlSeconds);
        }

        return $next($request);
    }
}
