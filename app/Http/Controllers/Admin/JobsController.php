<?php

namespace App\Http\Controllers\Admin;

use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Controller;
use App\Mail\BookingReceiptMail;
use App\Models\Booking;
use App\Models\User;
use App\Services\DocumentGenerationService;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Support\Facades\Mail;

class JobsController extends Controller
{
    protected array $activeStatuses = [
        'assigned', 'on_the_way', 'in_progress',
        'waiting_verification', 'payment_pending', 'payment_submitted',
        'on_job', 'delayed',
    ];

    public function index()
    {
        $jobs = Booking::with(['customer', 'truckType', 'unit.driver', 'unit.teamLeader', 'assignedTeamLeader'])
            ->whereIn('status', $this->activeStatuses)
            ->latest()
            ->paginate(12);

        $stats = [
            'total'    => Booking::whereIn('status', $this->activeStatuses)->count(),
            'on_job'   => Booking::whereIn('status', ['on_the_way', 'in_progress', 'on_job'])->count(),
            'assigned' => Booking::where('status', 'assigned')->count(),
            'delayed'  => Booking::where('status', 'delayed')->count(),
            'awaiting_payment' => Booking::whereIn('status', ['payment_pending', 'payment_submitted'])->count(),
        ];

        return view('admin-dashboard.pages.jobs', compact('jobs', 'stats'));
    }

    public function confirmPayment(Booking $booking)
    {
        $readyStatuses = ['waiting_verification', 'payment_pending', 'payment_submitted'];
        if (! in_array($booking->status, $readyStatuses, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This booking is not ready for completion.',
            ], 422);
        }

        $booking->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        $booking->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'receipt']);

        // Release unit and team leader
        if ($booking->assigned_unit_id) {
            \App\Models\Unit::whereKey($booking->assigned_unit_id)->update(['status' => 'available']);
        }
        if ($booking->assigned_team_leader_id) {
            $tl = User::find($booking->assigned_team_leader_id);
            if ($tl) {
                app(TeamLeaderAvailabilityService::class)->setOperationalOverride($tl, 'available');
            }
        }

        BookingStatusUpdated::safeFire($booking);

        // PDF generation and email are deferred until after the HTTP response is sent
        // so the dispatcher sees the confirmation immediately instead of waiting 10-30s.
        $bookingId = $booking->id;
        app()->terminating(function () use ($bookingId) {
            try {
                $b = Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'receipt'])
                    ->find($bookingId);
                if (! $b) {
                    return;
                }

                $documentService = app(DocumentGenerationService::class);
                $finalQuotePath  = $documentService->generateQuotation($b, true);
                $b->update(['final_quote_path' => $finalQuotePath]);

                $receipt = $documentService->generateReceipt($b);

                if (filled($b->customer?->email)) {
                    Mail::to($b->customer->email)->send(
                        new BookingReceiptMail($b->fresh(['customer', 'truckType', 'receipt']))
                    );
                    $receipt->update(['email_sent' => true]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Background receipt generation failed', [
                    'booking_id' => $bookingId,
                    'error'      => $e->getMessage(),
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment confirmed. Job completed and receipt sent to customer.',
        ]);
    }
}
