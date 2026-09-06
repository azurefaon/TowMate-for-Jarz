<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DispatcherNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class DispatcherNotificationController extends Controller
{
    /**
     * Full notification history — the "View all notifications" destination
     * linked from the top bar dropdown. Reuses the same notification-item
     * partial as the dropdown, just paginated.
     */
    public function index(): View
    {
        $notifications = DispatcherNotification::with(['booking.customer', 'booking.truckType'])
            ->latest('id')
            ->paginate(25);

        return view('admin-dashboard.pages.notifications', compact('notifications'));
    }

    /**
     * Marks all currently-unread notifications as read. Shared queue (see
     * DispatcherNotification), so this affects the whole Dispatcher team,
     * matching how the rest of the dispatch board is shared operational state.
     */
    public function markAllRead(): JsonResponse
    {
        DispatcherNotification::unread()->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'unreadCount' => 0,
        ]);
    }

    /**
     * Marks one notification read, then sends the Dispatcher to the existing
     * page that already handles this booking (Dispatch Queue or Active
     * Jobs) — no separate booking-detail view is built just for this.
     */
    public function open(DispatcherNotification $notification): RedirectResponse
    {
        if (! $notification->isRead()) {
            $notification->update(['read_at' => now()]);
        }

        return redirect($notification->target_url);
    }
}
