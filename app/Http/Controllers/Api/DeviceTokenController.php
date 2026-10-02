<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * Idempotent. The authenticated user is the only possible owner; a token
     * already stored for another account (shared device, re-login) is moved.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'    => 'required|string|min:20|max:512',
            'platform' => 'nullable|string|in:android',
        ]);

        DeviceToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id'      => $request->user()->id,
                'platform'     => $validated['platform'] ?? 'android',
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['success' => true]);
    }

    /**
     * Ownership-safe: only removes the caller's own row. Always succeeds so it
     * leaks nothing about tokens that belong to other accounts.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:512',
        ]);

        DeviceToken::where('token', $validated['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['success' => true]);
    }
}
