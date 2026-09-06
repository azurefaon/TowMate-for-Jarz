<?php

namespace App\Http\Controllers\Admin;

use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Controller;
use App\Mail\BookingReceiptMail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Services\CustomerNotificationService;
use App\Services\DocumentGenerationService;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class JobsController extends Controller
{
    protected array $activeStatuses = [
        'assigned', 'accepted', 'on_the_way', 'arrived_pickup',
        'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff',
        'waiting_verification', 'payment_pending', 'payment_submitted', 'delayed',
    ];

    protected array $verificationStatuses = ['waiting_verification', 'payment_pending', 'payment_submitted'];

    public function index()
    {
        $jobs = Booking::with(['customer', 'truckType', 'unit.driver', 'assignedTeamLeader'])
            ->whereIn('status', $this->activeStatuses)
            ->latest()
            ->paginate(12);

        $stats = [
            'total'             => Booking::whereIn('status', $this->activeStatuses)->count(),
            'operational'       => Booking::whereIn('status', array_values(array_diff($this->activeStatuses, $this->verificationStatuses)))->count(),
            'awaiting_payment'  => Booking::whereIn('status', $this->verificationStatuses)->count(),
            'delayed'           => Booking::where('status', 'delayed')->count(),
            // UI filter-group counts for the Active Jobs tabs — computed
            // independently of pagination (the table itself is paginated at
            // 12/page, so counting rendered rows client-side would be wrong).
            // Presentation groupings only; the underlying statuses/lifecycle
            // are unchanged.
            'assigned'              => Booking::whereIn('status', ['assigned', 'accepted'])->count(),
            'en_route'              => Booking::whereIn('status', ['on_the_way', 'arrived_pickup'])->count(),
            'in_service'            => Booking::whereIn('status', ['in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'])->count(),
            'awaiting_verification' => Booking::where('status', 'waiting_verification')->count(),
        ];

        return view('admin-dashboard.pages.jobs', compact('jobs', 'stats'));
    }

    public function confirmPayment(Request $request, Booking $booking)
    {
        $readyStatuses = ['waiting_verification', 'payment_pending', 'payment_submitted'];

        $outcome = DB::transaction(function () use ($booking, $readyStatuses) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if ($locked->status === 'completed') {
                return ['already' => $locked];
            }

            if (! in_array($locked->status, $readyStatuses, true)) {
                return ['error' => 'This booking is not ready for completion.'];
            }

            $locked->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            AuditLog::create([
                'user_id'     => auth()->id(),
                'action'      => 'payment_confirmed',
                'entity_type' => 'Booking',
                'entity_id'   => $locked->id,
                'reference'   => $locked->job_code,
                'description' => 'Job completed' . ($locked->cash_received !== null ? ' — cash received ₱' . number_format((float) $locked->cash_received, 2) : ''),
            ]);

            if (! $locked->payment_submitted_at) {
                if ($locked->assigned_unit_id) {
                    Unit::whereKey($locked->assigned_unit_id)->update(['status' => 'available']);
                }
                if ($locked->assigned_team_leader_id) {
                    $tl = User::find($locked->assigned_team_leader_id);
                    if ($tl) {
                        app(TeamLeaderAvailabilityService::class)->setOperationalOverride($tl, 'available');
                    }
                }
            }

            return ['booking' => $locked];
        });

        if (isset($outcome['error'])) {
            return response()->json(['success' => false, 'message' => $outcome['error']], 422);
        }

        if (isset($outcome['already'])) {
            return response()->json([
                'success' => true,
                'message' => 'This job was already confirmed.',
            ]);
        }

        $booking = $outcome['booking'];
        $booking->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'receipt']);

        BookingStatusUpdated::safeFire($booking);

        // Notify customer that their booking is complete
        if ($booking->customer && $booking->customer->user_id) {
            CustomerNotificationService::send(
                userId: $booking->customer->user_id,
                type: 'booking_update',
                title: 'Your booking is complete',
                body: 'Booking ' . $booking->booking_code . ' has been completed.',
                bookingCode: $booking->booking_code,
            );
        }

        // PDF generation and email are deferred until after the HTTP response is sent
        // so the dispatcher sees the confirmation immediately instead of waiting 10-30s.
        $bookingId = $booking->id;
        app()->terminating(function () use ($bookingId) {
            // Flush the HTTP response to the client before heavy work so the
            // browser gets the JSON immediately (built-in dev server + PHP-FPM).
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

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
