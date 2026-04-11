<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index()
    {
        $customerId = $this->resolveCustomerId();
        $activeStatuses = ['requested', 'reviewed', 'quoted', 'quotation_sent', 'confirmed', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job'];

        $totalBookings = $customerId
            ? Booking::where('customer_id', $customerId)->count()
            : 0;

        $activeBookings = $customerId
            ? Booking::where('customer_id', $customerId)->whereIn('status', $activeStatuses)->count()
            : 0;

        $totalSpent = $customerId
            ? Booking::where('customer_id', $customerId)->sum('final_total')
            : 0;

        $activeBooking = $customerId
            ? Booking::where('customer_id', $customerId)
            ->whereIn('status', $activeStatuses)
            ->latest('updated_at')
            ->first()
            : null;

        $activities = $customerId
            ? Booking::where('customer_id', $customerId)
            ->latest('updated_at')
            ->take(5)
            ->get()
            ->map(fn(Booking $booking) => (object) $this->formatActivity($booking))
            : collect();

        return view('customer.pages.dashboard', compact(
            'totalBookings',
            'activeBookings',
            'totalSpent',
            'activeBooking',
            'activities'
        ));
    }

    protected function formatActivity(Booking $booking): array
    {
        $meta = match ($booking->status) {
            'requested' => [
                'title' => 'Request submitted',
                'description' => 'Your towing request is waiting for dispatcher review.',
                'icon' => 'clipboard-list',
                'status' => 'pending',
                'label' => 'Queued',
            ],
            'quoted', 'quotation_sent' => [
                'title' => 'Quotation ready',
                'description' => 'A price proposal is ready for your review.',
                'icon' => 'receipt-text',
                'status' => 'attention',
                'label' => 'Review',
            ],
            'reviewed' => [
                'title' => 'Negotiation received',
                'description' => 'Your request for changes is under review.',
                'icon' => 'message-square-quote',
                'status' => 'attention',
                'label' => 'Pending',
            ],
            'confirmed', 'accepted', 'assigned', 'on_the_way', 'on_job', 'in_progress' => [
                'title' => 'Tow service in motion',
                'description' => 'Your unit is being prepared or is already on the way.',
                'icon' => 'truck',
                'status' => 'live',
                'label' => 'Live',
            ],
            'waiting_verification' => [
                'title' => 'Verification requested',
                'description' => 'Please confirm the task completion details.',
                'icon' => 'shield-check',
                'status' => 'pending',
                'label' => 'Action',
            ],
            'completed' => [
                'title' => 'Service completed',
                'description' => 'This booking has been successfully completed.',
                'icon' => 'badge-check',
                'status' => 'success',
                'label' => 'Done',
            ],
            'cancelled', 'rejected' => [
                'title' => 'Booking closed',
                'description' => 'This request is no longer active.',
                'icon' => 'x-circle',
                'status' => 'dark',
                'label' => 'Closed',
            ],
            default => [
                'title' => 'Status updated',
                'description' => 'Your booking has a fresh service update.',
                'icon' => 'bell-ring',
                'status' => 'live',
                'label' => 'Updated',
            ],
        };

        return [
            'icon' => $meta['icon'],
            'title' => $meta['title'] . ' · ' . $booking->job_code,
            'description' => $meta['description'],
            'status' => $meta['status'],
            'label' => $meta['label'],
            'updated_at' => $booking->updated_at?->diffForHumans() ?? 'just now',
        ];
    }

    protected function resolveCustomerId(): ?int
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        if (Schema::hasColumn('customers', 'user_id') && $user->customer) {
            return $user->customer->id;
        }

        return Customer::query()
            ->when(Schema::hasColumn('customers', 'user_id'), function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->when(filled($user->email ?? null), function ($query) use ($user) {
                $query->orWhere('email', $user->email);
            })
            ->value('id');
    }
}
