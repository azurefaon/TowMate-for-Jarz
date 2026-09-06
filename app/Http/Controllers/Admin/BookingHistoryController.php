<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class BookingHistoryController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'all');
        $search = trim((string) $request->query('search', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        // 'rejected' and 'not_responding' are both terminal, non-completed outcomes —
        // shown under the same "Cancelled" tab/badge as 'cancelled' (see _table.blade.php
        // for the per-status label/reason shown), so they don't just disappear from
        // history once they leave the active Book Now queue.
        $cancelledLikeStatuses = ['cancelled', 'rejected', 'not_responding'];

        $bookings = Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader'])
            ->whereIn('status', array_merge(['completed'], $cancelledLikeStatuses))
            ->when($status === 'completed', function ($query) {
                $query->where('status', 'completed');
            })
            ->when($status === 'cancelled', function ($query) use ($cancelledLikeStatuses) {
                $query->whereIn('status', $cancelledLikeStatuses);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('booking_code', 'ilike', "%{$search}%")
                        ->orWhereHas('customer', function ($cq) use ($search) {
                            $cq->where('full_name', 'ilike', "%{$search}%");
                        });
                });
            })
            // Same timestamp the Date column itself displays (updated_at, since
            // completing a booking also bumps updated_at to completed_at in the
            // same update() call) — no new/invented timestamp semantics.
            ->when($dateFrom !== '', function ($query) use ($dateFrom) {
                $query->whereDate('updated_at', '>=', $dateFrom);
            })
            ->when($dateTo !== '', function ($query) use ($dateTo) {
                $query->whereDate('updated_at', '<=', $dateTo);
            })
            ->orderByDesc('updated_at')
            ->paginate(10)
            ->withQueryString();

        $counts = [
            'completed' => Booking::where('status', 'completed')->count(),
            'cancelled' => Booking::whereIn('status', $cancelledLikeStatuses)->count(),
        ];
        $counts['all'] = $counts['completed'] + $counts['cancelled'];

        if ($request->ajax()) {
            return view('admin-dashboard.pages.booking-history._table', compact('bookings'));
        }

        return view('admin-dashboard.pages.booking-history.index', compact('bookings', 'counts', 'status', 'search', 'dateFrom', 'dateTo'));
    }
}
