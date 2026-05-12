<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamLeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || (int) $user->role_id !== 3) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Team Leader role required.',
            ], 403);
        }

        return $next($request);
    }
}
