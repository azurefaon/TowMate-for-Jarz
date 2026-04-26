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
        if ($booking->status !== 'payment_submitted') {
            return response()->json([
                'success' => false,
                'message' => 'This booking is not ready for payment confirmation.',
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

        event(new BookingStatusUpdated($booking));

        // Generate final quotation PDF and receipt, then email customer
        $documentService = app(DocumentGenerationService::class);

        $finalQuotePath = $documentService->generateQuotation($booking, true);
        $booking->update(['final_quote_path' => $finalQuotePath]);

        $receipt = $documentService->generateReceipt($booking);

        if (filled($booking->customer?->email)) {
            Mail::to($booking->customer->email)->send(
                new BookingReceiptMail($booking->fresh(['customer', 'truckType', 'receipt']))
            );
            $receipt->update(['email_sent' => true]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment confirmed. Job completed and receipt sent to customer.',
        ]);
    }
}
