<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Immediately terminate sessions for archived or deactivated accounts.
        if ($user->archived_at !== null || $user->status === 'inactive') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')
                ->withErrors(['auth' => 'Your account access has been revoked. Contact your administrator.']);
        }

        if (! in_array((string) $user->role_id, array_map('strval', $roles), true)) {
            abort(403);
        }

        return $next($request);
    }
}
