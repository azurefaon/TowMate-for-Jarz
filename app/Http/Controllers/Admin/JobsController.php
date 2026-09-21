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
        $allJobs = Booking::with(['customer', 'truckType', 'unit.driver', 'assignedTeamLeader'])
            ->whereIn('status', $this->activeStatuses)
            ->latest()
            ->get();

        $groups = $allJobs
            ->groupBy(fn(Booking $b) => $b->group_code
                ? $b->group_code . '|' . $b->pickup_address . '|' . $b->dropoff_address
                : 'solo-' . $b->id)
            ->map(function ($members) {
                $sorted = $members->sortBy('id')->values();
                $primary = $sorted->first();
                $primary->sibling_bookings = $sorted;
                $primary->group_total = $this->isNormalizedGroupBooking($primary)
                    ? $this->activeGroupTotal($primary, $sorted)
                    : null;
                return $primary;
            })
            ->values()
            ->sortByDesc(fn(Booking $b) => $b->created_at?->getTimestamp() ?? 0)
            ->values();

        $page = (int) request('page', 1);
        $perPage = 12;
        $jobs = new \Illuminate\Pagination\LengthAwarePaginator(
            $groups->forPage($page, $perPage),
            $groups->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );

        $stats = [
            'total'             => Booking::whereIn('status', $this->activeStatuses)->count(),
            'operational'       => Booking::whereIn('status', array_values(array_diff($this->activeStatuses, $this->verificationStatuses)))->count(),
            'awaiting_payment'  => Booking::whereIn('status', $this->verificationStatuses)->count(),
            'delayed'           => Booking::where('status', 'delayed')->count(),
            'assigned'              => Booking::whereIn('status', ['assigned', 'accepted'])->count(),
            'en_route'              => Booking::whereIn('status', ['on_the_way', 'arrived_pickup'])->count(),
            'in_service'            => Booking::whereIn('status', ['in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'])->count(),
            'awaiting_verification' => Booking::where('status', 'waiting_verification')->count(),
        ];

        return view('admin-dashboard.pages.jobs', compact('jobs', 'stats'));
    }

    private function isNormalizedGroupBooking(Booking $booking): bool
    {
        if (! $booking->group_code || ! $booking->quotation_id) {
            return false;
        }
        $quotation = \App\Models\Quotation::find($booking->quotation_id);
        if (! $quotation) {
            return false;
        }
        return collect($quotation->extra_vehicles ?? [])->contains(fn($ev) => ! empty($ev['booking_id']));
    }

    private function activeGroupTotal(Booking $primary, \Illuminate\Support\Collection $activeMembers): ?float
    {
        $quotation = \App\Models\Quotation::find($primary->quotation_id);
        if (! $quotation) {
            return null;
        }

        $expectedMemberCount = 1 + collect($quotation->extra_vehicles ?? [])
            ->filter(fn($ev) => ! empty($ev['booking_id']))
            ->count();

        if ($activeMembers->count() >= $expectedMemberCount) {
            return (float) $quotation->estimated_price;
        }

        $adjustment = (float) ($quotation->additional_fee ?? 0) - (float) ($quotation->discount ?? 0);

        return max(round($activeMembers->sum(fn($member) => (float) $member->final_total) + $adjustment, 2), 0);
    }

    public function confirmPayment(Request $request, Booking $booking)
    {
        $readyStatuses = ['waiting_verification', 'payment_pending', 'payment_submitted'];
        $isGroup = $this->isNormalizedGroupBooking($booking);

        $outcome = DB::transaction(function () use ($booking, $readyStatuses, $isGroup) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if ($locked->status === 'completed') {
                return ['already' => $locked];
            }

            if (! in_array($locked->status, $readyStatuses, true)) {
                return ['error' => 'This booking is not ready for completion.'];
            }

            $members = $isGroup
                ? Booking::where('group_code', $locked->group_code)
                    ->where('pickup_address', $locked->pickup_address)
                    ->where('dropoff_address', $locked->dropoff_address)
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->get()
                    ->reject(fn($member) => $member->status === 'cancelled')
                    ->values()
                : collect([$locked]);

            $notReady = $members->filter(fn($member) => $member->id !== $locked->id
                && $member->status !== 'completed'
                && ! in_array($member->status, $readyStatuses, true));
            if ($notReady->isNotEmpty()) {
                return ['error' => 'All vehicles in this group must submit payment before it can be confirmed.'];
            }

            foreach ($members as $member) {
                if ($member->status === 'completed') {
                    continue;
                }

                $wasSubmitted = $member->payment_submitted_at !== null;

                $member->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                ]);

                if (! $wasSubmitted) {
                    if ($member->assigned_unit_id) {
                        Unit::whereKey($member->assigned_unit_id)->update(['status' => 'available']);
                    }
                    if ($member->assigned_team_leader_id) {
                        $tl = User::find($member->assigned_team_leader_id);
                        if ($tl) {
                            app(TeamLeaderAvailabilityService::class)->setOperationalOverride($tl, 'available');
                        }
                    }
                }
            }

            AuditLog::create([
                'user_id'     => auth()->id(),
                'action'      => 'payment_confirmed',
                'entity_type' => 'Booking',
                'entity_id'   => $locked->id,
                'reference'   => $locked->job_code,
                'description' => 'Job completed' . ($locked->cash_received !== null ? ' — cash received ₱' . number_format((float) $locked->cash_received, 2) : ''),
            ]);

            return ['booking' => $locked, 'members' => $members];
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
        $members = $outcome['members'];
        $booking->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'receipt']);

        BookingStatusUpdated::safeFire($booking);
        foreach ($members as $member) {
            if ($member->id === $booking->id) {
                continue;
            }
            try { BookingStatusUpdated::safeFire($member->fresh()); } catch (\Throwable) {}
        }

        if ($booking->customer && $booking->customer->user_id) {
            CustomerNotificationService::send(
                userId: $booking->customer->user_id,
                type: 'booking_update',
                title: 'Your booking is complete',
                body: 'Booking ' . $booking->booking_code . ' has been completed.',
                bookingCode: $booking->booking_code,
            );
        }

        $bookingId = $booking->id;
        $quotationId = $booking->quotation_id;
        app()->terminating(function () use ($bookingId, $isGroup, $quotationId) {
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
                if ($b->receipt && $b->receipt->email_sent) {
                    return;
                }

                $documentService = app(DocumentGenerationService::class);
                $finalQuotePath  = $documentService->generateQuotation($b, true);
                $b->update(['final_quote_path' => $finalQuotePath]);

                if ($isGroup && $quotationId) {
                    $quotation = \App\Models\Quotation::find($quotationId);
                    $cancelledGroupBookingIds = Booking::where('group_code', $b->group_code)
                        ->where('status', 'cancelled')
                        ->pluck('id');
                    $groupVehicles = collect($quotation->extra_vehicles ?? [])
                        ->reject(fn($ev) => in_array($ev['booking_id'] ?? null, $cancelledGroupBookingIds->all(), true))
                        ->map(function ($ev) {
                            $truckTypeName = $ev['truck_type_name']
                                ?? (\App\Models\TruckType::find($ev['truck_type_id'] ?? null)?->name);
                            return [
                                'truck_type_name' => $truckTypeName ?? 'Towing Service',
                                'final_total'     => (float) ($ev['final_total'] ?? $ev['estimated_price'] ?? 0),
                            ];
                        })->values()->all();
                    $groupAdjustment = (float) ($quotation->additional_fee ?? 0) - (float) ($quotation->discount ?? 0);
                    $receipt = $documentService->generateReceipt($b, $groupVehicles, $groupAdjustment);
                } else {
                    $receipt = $documentService->generateReceipt($b);
                }

                if (filled($b->customer?->email)) {
                    Mail::to($b->customer->email)->send(
                        new BookingReceiptMail(
                            $b->fresh(['customer', 'truckType', 'receipt']),
                            $groupVehicles ?? [],
                            $groupAdjustment ?? 0.0
                        )
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
