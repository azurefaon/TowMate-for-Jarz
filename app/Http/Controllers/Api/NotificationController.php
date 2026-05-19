<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $notifications = CustomerNotification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $unreadCount = CustomerNotification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'success'      => true,
            'unread_count' => $unreadCount,
            'data'         => $notifications->map(fn($n) => [
                'id'           => $n->id,
                'type'         => $n->type,
                'title'        => $n->title,
                'body'         => $n->body,
                'booking_code' => $n->booking_code,
                'is_read'      => $n->is_read,
                'created_at'   => $n->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        CustomerNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = CustomerNotification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $notification) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 403);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }
}
