<?php

namespace App\Services;

use App\Events\BookingStatusUpdated;
use App\Exceptions\Booking\ScheduledQuoteCutoffPassedException;
use App\Models\Booking;
use App\Models\PriceAdjustment;
use App\Models\Quotation;
use App\Models\TruckType;
use App\Services\CustomerNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class QuotationService
{
    private function bookingService(): BookingService
    {
        return app(BookingService::class);
    }

    private function resolveExpiry(Quotation $quotation, Carbon $candidate): Carbon
    {
        if ($quotation->service_type === 'book_now' || ! $quotation->scheduled_for) {
            return $candidate;
        }

        $cutoff = $quotation->scheduled_for->copy()->subHours(2);

        if ($cutoff->isPast()) {
            throw new ScheduledQuoteCutoffPassedException(
                'The quote window for this scheduled service has closed. Reschedule this booking to a later time before sending a quote.'
            );
        }

        return $candidate->lessThan($cutoff) ? $candidate : $cutoff;
    }

    private function appendSentVersionEntry(array $log, int $version): array
    {
        foreach ($log as $entry) {
            if (($entry['type'] ?? null) === 'quotation_sent' && (int) ($entry['version'] ?? 0) === $version) {
                return $log;
            }
        }

        $log[] = [
            'at' => now()->toISOString(),
            'type' => 'quotation_sent',
            'version' => $version,
        ];

        return $log;
    }

    public function generateQuotationNumber(): string
    {
        $prefix = 'QT';
        $date = now()->format('Ymd');

        $lastQuotation = Quotation::whereDate('created_at', today())
            ->orderBy('id', 'desc')
            ->first();

        if ($lastQuotation && $lastQuotation->quotation_number) {
            $lastNumber = (int) substr($lastQuotation->quotation_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "{$prefix}-{$date}-{$newNumber}";
    }

    public function createQuotation(array $data): Quotation
    {
        return Quotation::create([
            'quotation_number' => $this->generateQuotationNumber(),
            'source_booking_id' => $data['source_booking_id'] ?? null,
            'customer_id' => $data['customer_id'],
            'truck_type_id' => $data['truck_type_id'],
            'pickup_address' => $data['pickup_address'],
            'dropoff_address' => $data['dropoff_address'],
            'pickup_notes' => $data['pickup_notes'] ?? null,
            'distance_km' => $data['distance_km'],
            'eta_minutes' => $data['eta_minutes'] ?? null,
            'vehicle_make' => $data['vehicle_make'] ?? null,
            'vehicle_model' => $data['vehicle_model'] ?? null,
            'vehicle_year' => $data['vehicle_year'] ?? null,
            'vehicle_color' => $data['vehicle_color'] ?? null,
            'vehicle_plate_number' => $data['vehicle_plate_number'] ?? null,
            'vehicle_image_path' => $data['vehicle_image_path'] ?? null,
            'extra_vehicles' => $data['extra_vehicles'] ?? null,
            'estimated_price' => $data['estimated_price'],
            'vat_rate' => $data['vat_rate'] ?? $this->bookingService()->vatRate(),
            'service_type' => $data['service_type'] ?? null,
            'scheduled_date' => $data['scheduled_date'] ?? null,
            'scheduled_time' => $data['scheduled_time'] ?? null,
            'status' => 'pending',
        ]);
    }

    public function hasActiveQuotation(int $customerId): bool
    {
        return Quotation::where('customer_id', $customerId)
            ->current()
            ->whereIn('status', ['pending', 'sent'])
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public function getActiveQuotation(int $customerId): ?Quotation
    {
        return Quotation::where('customer_id', $customerId)
            ->current()
            ->whereIn('status', ['pending', 'sent'])
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->with(['truckType', 'customer'])
            ->first();
    }

    public function sendQuotation(Quotation $quotation, int $expiryHours = 168): Quotation
    {
        $expiresAt = $this->resolveExpiry($quotation, now()->addHours($expiryHours));
        $log = $this->appendSentVersionEntry($quotation->price_change_log ?? [], (int) ($quotation->version ?: 1));

        $quotation->update([
            'status' => 'sent',
            'sent_at' => now(),
            'expires_at' => $expiresAt,
            'expiry_hours' => $expiryHours,
            'price_change_log' => $log,
        ]);

        if ($quotation->customer && $quotation->customer->email) {
            try {
                \Illuminate\Support\Facades\Mail::to($quotation->customer->email)
                    ->send(new \App\Mail\QuotationSentMail($quotation));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send quotation email', [
                    'quotation_id' => $quotation->id,
                    'customer_email' => $quotation->customer->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($quotation->customer && $quotation->customer->user_id) {
            $bookingCode = $quotation->sourceBooking?->booking_code;
            CustomerNotificationService::send(
                userId: $quotation->customer->user_id,
                type: 'quotation_sent',
                title: 'You have a new quotation to review',
                body: 'A price has been prepared for your booking. Tap to review and accept.',
                bookingCode: $bookingCode,
            );
        }

        return $quotation->fresh();
    }

    public function acceptQuotation(Quotation $quotation, ?string $note = null): Booking
    {
        DB::beginTransaction();

        try {
            if (! $quotation->is_current) {
                throw new \Exception('This quotation was revised. Please use the latest version.');
            }

            if ($quotation->status !== 'sent') {
                throw new \Exception('Quotation already processed.');
            }

            $quotation->update([
                'status' => 'accepted',
                'responded_at' => now(),
                'response_note' => $note,
            ]);

            $groupCode = $quotation->quotation_number;
            $isScheduled = $quotation->service_type === 'schedule';
            $extraVehicles = $quotation->extra_vehicles ?? [];
            $isNormalizedGroup = collect($extraVehicles)->contains(fn ($ev) => ! empty($ev['booking_id']));

            $primaryBooking = $quotation->source_booking_id ? Booking::findOrFail($quotation->source_booking_id) : null;
            $vatRate = $this->bookingService()->resolveVatRate(
                $quotation->vat_rate !== null ? (float) $quotation->vat_rate : null,
                $primaryBooking?->vat_amount !== null ? (float) $primaryBooking->vat_amount : null,
                $primaryBooking?->vat_exclusive_total !== null ? (float) $primaryBooking->vat_exclusive_total : null,
            );

            if ($primaryBooking) {
                $subtotal     = $this->bookingService()->resolveTaxableSubtotal($primaryBooking);
                $vatAmount    = round($subtotal * $vatRate, 2);
                $vatExclusive = $subtotal;
                $finalTotal   = $isNormalizedGroup ? round($vatExclusive + $vatAmount, 2) : (float) $quotation->estimated_price;

                $primaryBooking->update(array_merge([
                    'quotation_id'         => $quotation->id,
                    'final_total'          => $finalTotal,
                    'vat_amount'           => $vatAmount,
                    'vat_exclusive_total'  => $vatExclusive,
                    'vat_rate'             => $vatRate,
                    'status'               => $isScheduled ? 'scheduled_confirmed' : 'confirmed',
                    'customer_approved_at' => now(),
                    'price_locked_at'      => now(),
                ], $isScheduled ? ['selected_unit_id' => null] : [], $isNormalizedGroup ? ['additional_fee' => 0.0] : []));
            } else {
                $finalTotal   = (float) $quotation->estimated_price;
                $vatExclusive = round($finalTotal / (1 + $vatRate), 2);
                $vatAmount    = round($finalTotal - $vatExclusive, 2);

                $primaryBooking = Booking::create([
                    'quotation_id'        => $quotation->id,
                    'group_code'          => $groupCode,
                    'customer_id'         => $quotation->customer_id,
                    'truck_type_id'       => $quotation->truck_type_id,
                    'pickup_address'      => $quotation->pickup_address,
                    'dropoff_address'     => $quotation->dropoff_address,
                    'pickup_notes'        => $quotation->pickup_notes,
                    'distance_km'         => $quotation->distance_km,
                    'eta_minutes'         => $quotation->eta_minutes,
                    'vehicle_image_path'  => $quotation->vehicle_image_path,
                    'final_total'         => $finalTotal,
                    'vat_amount'          => $vatAmount,
                    'vat_exclusive_total' => $vatExclusive,
                    'vat_rate'            => $vatRate,
                    'service_type'        => $quotation->service_type,
                    'scheduled_date'      => $quotation->scheduled_date?->toDateString(),
                    'scheduled_time'      => $quotation->scheduled_time,
                    'scheduled_expires_at' => $isScheduled ? now()->addDays(7) : null,
                    'status'              => $isScheduled ? 'scheduled_confirmed' : 'confirmed',
                    'customer_approved_at' => now(),
                    'price_locked_at'     => now(),
                ]);
            }

            if ($isScheduled && $quotation->scheduled_date) {
                $date = $quotation->scheduled_date->toDateString();
                DB::statement(
                    "INSERT INTO booking_capacity (booking_date, slots_used, updated_at)
                     VALUES (?, 1, NOW())
                     ON CONFLICT (booking_date) DO UPDATE
                     SET slots_used = booking_capacity.slots_used + 1, updated_at = NOW()",
                    [$date]
                );
            }

            foreach ($extraVehicles as $ev) {
                $evBookingId = $ev['booking_id'] ?? null;

                if ($evBookingId) {
                    if ((int) $evBookingId === (int) $quotation->source_booking_id) {
                        continue;
                    }

                    $siblingBooking = Booking::find($evBookingId);
                    if (! $siblingBooking) {
                        continue;
                    }

                    $evIsScheduled = ($ev['service_type'] ?? $siblingBooking->service_type) === 'schedule';
                    $evFinalTotal = (float) ($ev['final_total'] ?? $ev['estimated_price'] ?? 0);
                    $evBaseRate = (float) ($ev['base_rate'] ?? 0);
                    $evDistanceFee = (float) ($ev['distance_fee'] ?? 0);
                    $evVatExclusive = isset($ev['vat_exclusive_total'])
                        ? (float) $ev['vat_exclusive_total']
                        : round($evBaseRate + $evDistanceFee, 2);
                    $evVatAmount = isset($ev['vat_amount']) ? round((float) $ev['vat_amount'], 2) : round($evVatExclusive * $vatRate, 2);
                    $evVatRate = $this->bookingService()->resolveVatRate(
                        isset($ev['vat_rate']) && $ev['vat_rate'] !== null ? (float) $ev['vat_rate'] : null,
                        $evVatAmount,
                        $evVatExclusive,
                    );
                    $evServiceTotal = round($evVatExclusive + $evVatAmount, 2);
                    $evAdjustment = round($evFinalTotal - $evServiceTotal, 2);

                    $siblingBooking->update(array_merge([
                        'quotation_id'         => $quotation->id,
                        'final_total'          => $evFinalTotal,
                        'vat_amount'           => $evVatAmount,
                        'vat_exclusive_total'  => $evVatExclusive,
                        'vat_rate'             => $evVatRate,
                        'additional_fee'       => $evAdjustment,
                        'status'               => $evIsScheduled ? 'scheduled_confirmed' : 'confirmed',
                        'customer_approved_at' => now(),
                        'price_locked_at'      => now(),
                    ], $evIsScheduled ? ['selected_unit_id' => null] : []));

                    if ($evIsScheduled && $siblingBooking->scheduled_date) {
                        DB::statement(
                            "INSERT INTO booking_capacity (booking_date, slots_used, updated_at)
                             VALUES (?, 1, NOW())
                             ON CONFLICT (booking_date) DO UPDATE
                             SET slots_used = booking_capacity.slots_used + 1, updated_at = NOW()",
                            [$siblingBooking->scheduled_date->toDateString()]
                        );
                    }

                    continue;
                }

                $evTruckTypeId = $ev['truck_type_id'] ?? null;
                $evServiceType = $ev['service_type'] ?? $quotation->service_type ?? 'book_now';
                $evScheduled = $evServiceType === 'schedule';
                $evDate = $ev['scheduled_date'] ?? null;
                $evTime = $ev['scheduled_time'] ?? null;
                $evPrice = $ev['estimated_price'] ?? 0;

                if (! $evTruckTypeId) {
                    continue;
                }

                $evTruckType = TruckType::find($evTruckTypeId);
                if (! $evTruckType) {
                    continue;
                }

                Booking::create([
                    'quotation_id' => $quotation->id,
                    'group_code' => $groupCode,
                    'customer_id' => $quotation->customer_id,
                    'truck_type_id' => $evTruckTypeId,
                    'pickup_address' => $quotation->pickup_address,
                    'dropoff_address' => $quotation->dropoff_address,
                    'pickup_notes' => $quotation->pickup_notes,
                    'distance_km' => $ev['distance_km'] ?? $quotation->distance_km,
                    'final_total' => $evPrice,
                    'service_type' => $evServiceType,
                    'scheduled_date' => $evDate,
                    'scheduled_time' => $evTime,
                    'scheduled_expires_at' => $evScheduled ? now()->addDays(7) : null,
                    'status' => $evScheduled ? 'scheduled_confirmed' : 'confirmed',
                    'customer_approved_at' => now(),
                    'price_locked_at' => now(),
                ]);

                if ($evScheduled && $evDate) {
                    DB::statement(
                        "INSERT INTO booking_capacity (booking_date, slots_used, updated_at)
                         VALUES (?, 1, NOW())
                         ON CONFLICT (booking_date) DO UPDATE
                         SET slots_used = booking_capacity.slots_used + 1, updated_at = NOW()",
                        [$evDate]
                    );
                }
            }

            DB::commit();

            $primaryBooking->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
            BookingStatusUpdated::safeFire($primaryBooking);

            DB::commit();

            return $primaryBooking;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function rejectQuotation(Quotation $quotation, ?string $reason = null): void
    {
        if (! $quotation->is_current) {
            return;
        }

        $quotation->update([
            'status' => 'rejected',
            'responded_at' => now(),
            'response_note' => $reason,
        ]);

        if ($quotation->source_booking_id) {
            $booking = Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader'])
                ->find($quotation->source_booking_id);
            if ($booking) {
                $booking->update(['status' => 'cancelled']);
                BookingStatusUpdated::safeFire($booking);
            }
        }
    }

    public function cancelQuotation(Quotation $quotation): void
    {
        $quotation->update([
            'status' => 'cancelled',
            'responded_at' => now(),
            'response_note' => 'Cancelled by dispatcher.',
        ]);

        if ($quotation->source_booking_id) {
            $sourceBooking = Booking::find($quotation->source_booking_id);
            $rejectableStatuses = [
                'requested', 'reviewed', 'quoted', 'quotation_sent',
                'scheduled', 'scheduled_confirmed',
            ];
            if ($sourceBooking && in_array($sourceBooking->status, $rejectableStatuses)) {
                $newStatus = in_array($sourceBooking->status, ['scheduled', 'scheduled_confirmed'])
                    ? 'rejected'
                    : 'cancelled';
                $sourceBooking->update([
                    'status'           => $newStatus,
                    'quotation_status' => 'cancelled',
                    'rejection_reason' => 'Booking cancelled by dispatcher.',
                ]);
                BookingStatusUpdated::safeFire($sourceBooking);
            }
        }

        if ($quotation->customer && $quotation->customer->email) {
            try {
                \Illuminate\Support\Facades\Mail::to($quotation->customer->email)
                    ->send(new \App\Mail\QuotationCancelledMail($quotation));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send quotation cancellation email', [
                    'quotation_id' => $quotation->id,
                    'customer_email' => $quotation->customer->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function requestPriceReview(Quotation $quotation, string $reason): void
    {
        if (! $quotation->is_current || $quotation->status !== 'sent') {
            return;
        }

        $log = $quotation->price_change_log ?? [];
        $log[] = [
            'at' => now()->toISOString(),
            'old' => (float) $quotation->estimated_price,
            'new' => (float) $quotation->estimated_price,
            'reason' => $reason,
            'by' => $quotation->customer?->full_name ?? 'Customer',
            'type' => 'price_review_requested',
        ];

        $quotation->update([
            'status' => 'price_review_requested',
            'response_note' => $reason,
            'responded_at' => now(),
            'price_change_log' => $log,
        ]);
    }

    public function keepCurrentPrice(Quotation $quotation, ?string $dispatcherNote = null): Quotation
    {
        if (! $quotation->is_current || $quotation->status !== 'price_review_requested') {
            throw new \Exception('This quotation is not awaiting a price review.');
        }

        $expiresAt = $this->resolveExpiry($quotation, now()->addHours($quotation->expiry_hours ?: 1));

        $log = $quotation->price_change_log ?? [];
        $log[] = [
            'at' => now()->toISOString(),
            'old' => (float) $quotation->estimated_price,
            'new' => (float) $quotation->estimated_price,
            'reason' => $dispatcherNote,
            'by' => auth()->user()?->name ?? 'Dispatcher',
            'type' => 'price_review_kept',
        ];
        $log = $this->appendSentVersionEntry($log, (int) ($quotation->version ?: 1));

        $quotation->update([
            'status' => 'sent',
            'sent_at' => now(),
            'expires_at' => $expiresAt,
            'response_note' => null,
            'responded_at' => null,
            'follow_up_sent_at' => null,
            'price_change_log' => $log,
        ]);

        if ($quotation->customer && $quotation->customer->email) {
            try {
                \Illuminate\Support\Facades\Mail::to($quotation->customer->email)
                    ->send(new \App\Mail\PriceReviewCompletedMail($quotation));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send price review completed email', [
                    'quotation_id' => $quotation->id,
                    'customer_email' => $quotation->customer->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($quotation->customer && $quotation->customer->user_id) {
            CustomerNotificationService::send(
                userId: $quotation->customer->user_id,
                type: 'quotation_price_review_kept',
                title: 'Price review completed',
                body: 'JARZ Towing Services has reviewed your request. The quotation amount remains unchanged.',
                bookingCode: $quotation->sourceBooking?->booking_code,
            );
        }

        return $quotation->fresh();
    }

    public function resolvePriceReviewWithNewPrice(Quotation $quotation, float $newPrice, ?string $note, float $additionalFee = 0): Quotation
    {
        if (! $quotation->is_current || $quotation->status !== 'price_review_requested') {
            throw new \Exception('This quotation is not awaiting a price review.');
        }

        $expiresAt = $this->resolveExpiry($quotation, now()->addHours($quotation->expiry_hours ?: 1));

        $log = $quotation->price_change_log ?? [];
        $log[] = [
            'at' => now()->toISOString(),
            'old' => (float) $quotation->estimated_price,
            'new' => $newPrice,
            'reason' => $note,
            'by' => auth()->user()?->name ?? 'Dispatcher',
            'type' => 'price_review_adjusted',
        ];
        $log = $this->appendSentVersionEntry($log, (int) ($quotation->version ?: 1) + 1);

        $this->recordAdjustmentDelta($quotation->quotation_number, (float) $quotation->additional_fee, $additionalFee, $note, auth()->id());

        $next = $quotation->newVersion([
            'estimated_price' => $newPrice,
            'additional_fee' => $additionalFee,
            'vat_rate' => $this->bookingService()->vatRate(),
            'counter_offer_amount' => null,
            'response_note' => null,
            'status' => 'sent',
            'sent_at' => now(),
            'expires_at' => $expiresAt,
            'responded_at' => null,
            'follow_up_sent_at' => null,
            'price_change_log' => $log,
        ]);

        if ($next->customer && $next->customer->email) {
            try {
                \Illuminate\Support\Facades\Mail::to($next->customer->email)
                    ->send(new \App\Mail\QuotationUpdatedMail($next));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send quotation updated email', [
                    'quotation_id' => $next->id,
                    'customer_email' => $next->customer->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($next->customer && $next->customer->user_id) {
            CustomerNotificationService::send(
                userId: $next->customer->user_id,
                type: 'quotation_updated',
                title: 'Your quotation price was updated',
                body: 'The price for your booking has been revised. Tap to view the updated quotation.',
                bookingCode: $next->sourceBooking?->booking_code,
            );
        }

        return $next;
    }

    public function negotiateQuotation(Quotation $quotation, ?float $counterOffer, string $note): void
    {
        $quotation->update([
            'status' => 'negotiating',
            'counter_offer_amount' => $counterOffer,
            'response_note' => $note,
            'responded_at' => now(),
        ]);
    }

    public function sendFollowUpReminders(): int
    {
        $quotations = $this->getQuotationsNeedingFollowUp();
        $count = 0;

        foreach ($quotations as $quotation) {
            if ($quotation->customer && $quotation->customer->email) {
                try {
                    \Illuminate\Support\Facades\Mail::to($quotation->customer->email)
                        ->send(new \App\Mail\QuotationFollowUpMail($quotation));
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to send quotation follow-up email', [
                        'quotation_id' => $quotation->id,
                        'customer_email' => $quotation->customer->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($quotation->customer && $quotation->customer->user_id) {
                $bookingCode = $quotation->sourceBooking?->booking_code;
                CustomerNotificationService::send(
                    userId: $quotation->customer->user_id,
                    type: 'quotation_followup',
                    title: 'Your quotation is still waiting',
                    body: 'You have a pending quotation that needs your response. Tap to review it.',
                    bookingCode: $bookingCode,
                );
            }

            $quotation->update(['follow_up_sent_at' => now()]);
            $count++;
        }

        return $count;
    }

    public function getQuotationsNeedingFollowUp()
    {
        return Quotation::whereIn('status', ['sent', 'negotiating'])
            ->current()
            ->whereNull('responded_at')
            ->whereNull('follow_up_sent_at')
            ->where('expires_at', '>', now())
            ->where(function ($query) {
                $query->where(function ($bookNow) {
                    $bookNow->where(function ($q) {
                        $q->whereNull('service_type')->orWhere('service_type', 'book_now');
                    })->where('sent_at', '<=', now()->subMinutes(30));
                })->orWhere(function ($scheduled) {
                    $scheduled->where('service_type', '!=', 'book_now')
                        ->where('sent_at', '<=', now()->subDays(5));
                });
            })
            ->with(['customer', 'truckType', 'sourceBooking'])
            ->get();
    }

    public function expireOldQuotations(): int
    {
        $quotations = Quotation::whereIn('status', ['sent', 'negotiating'])
            ->current()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with(['sourceBooking', 'customer'])
            ->get();

        $count = 0;

        foreach ($quotations as $quotation) {
            $quotation->update([
                'status' => 'expired',
            ]);

            $booking = $quotation->sourceBooking;
            if ($booking && !in_array($booking->status, ['completed', 'cancelled', 'rejected', 'confirmed', 'scheduled_confirmed'], true)) {
                $booking->update(['status' => 'not_responding']);
                BookingStatusUpdated::safeFire($booking->fresh(['customer', 'truckType']));
            }

            if ($quotation->customer && $quotation->customer->user_id) {
                $bookingCode = $booking?->booking_code;
                CustomerNotificationService::send(
                    userId: $quotation->customer->user_id,
                    type: 'quotation_expired',
                    title: 'Your quotation has expired',
                    body: 'You did not respond in time, so this quotation request has closed.',
                    bookingCode: $bookingCode,
                );
            }

            $count++;
        }

        return $count;
    }

    public function updateQuotationPrice(Quotation $quotation, float $newPrice): Quotation
    {
        $quotation->update([
            'estimated_price' => $newPrice,
            'counter_offer_amount' => null,
        ]);

        $quotation->increment('link_version');

        if ($quotation->status === 'sent') {
            $quotation->update([
                'sent_at' => now(),
                'expires_at' => $this->resolveExpiry($quotation, now()->addHours($quotation->expiry_hours)),
            ]);
        }

        return $quotation->fresh();
    }

    public function extendQuotation(Quotation $quotation, int $additionalHours = 24): Quotation
    {
        if ($quotation->expires_at) {
            $candidate = $quotation->isExpired()
                ? now()->addHours($additionalHours)
                : $quotation->expires_at->copy()->addHours($additionalHours);

            $quotation->update([
                'expires_at' => $this->resolveExpiry($quotation, $candidate),
                'status' => 'sent',
            ]);
        }

        return $quotation->fresh();
    }

    public function renewQuotation(Quotation $oldQuotation): Quotation
    {
        return $this->createQuotation([
            'customer_id' => $oldQuotation->customer_id,
            'truck_type_id' => $oldQuotation->truck_type_id,
            'pickup_address' => $oldQuotation->pickup_address,
            'dropoff_address' => $oldQuotation->dropoff_address,
            'pickup_notes' => $oldQuotation->pickup_notes,
            'distance_km' => $oldQuotation->distance_km,
            'vehicle_make' => $oldQuotation->vehicle_make,
            'vehicle_model' => $oldQuotation->vehicle_model,
            'vehicle_year' => $oldQuotation->vehicle_year,
            'vehicle_color' => $oldQuotation->vehicle_color,
            'vehicle_plate_number' => $oldQuotation->vehicle_plate_number,
            'vehicle_image_path' => $oldQuotation->vehicle_image_path,
            'extra_vehicles' => $oldQuotation->extra_vehicles,
            'estimated_price' => $oldQuotation->estimated_price,
            'vat_rate' => $this->bookingService()->resolveVatRate(
                $oldQuotation->vat_rate !== null ? (float) $oldQuotation->vat_rate : null,
            ),
        ]);
    }

    public function canonicalBaseTotalFor(?Booking $sourceBooking, bool $isEditableDraft = false): ?float
    {
        if (! $sourceBooking) {
            return null;
        }

        return $this->bookingService()->applyVatAndAdjustment(
            $this->bookingService()->taxableSubtotalFor($sourceBooking, $isEditableDraft),
            0,
        )['base_total'];
    }

    public function netActiveAdjustment(string $quotationNumber): float
    {
        $active = PriceAdjustment::forQuotation($quotationNumber)->active()->get();

        $added = (float) $active->where('type', 'add')->sum('amount');
        $deducted = (float) $active->where('type', 'deduct')->sum('amount');

        return round($added - $deducted, 2);
    }

    public function recordPriceAdjustments(string $quotationNumber, array $items, ?int $userId): void
    {
        foreach ($items as $item) {
            PriceAdjustment::create([
                'quotation_number' => $quotationNumber,
                'type' => $item['type'],
                'amount' => round(abs((float) $item['amount']), 2),
                'reason' => $item['reason'] ?? null,
                'status' => 'active',
                'created_by' => $userId,
            ]);
        }
    }

    public function recordAdjustmentDelta(string $quotationNumber, float $previousAdditionalFee, float $newAdditionalFee, ?string $reason, ?int $userId): void
    {
        $delta = round($newAdditionalFee - $previousAdditionalFee, 2);

        if (abs($delta) < 0.005) {
            return;
        }

        $this->recordPriceAdjustments($quotationNumber, [[
            'type' => $delta > 0 ? 'add' : 'deduct',
            'amount' => abs($delta),
            'reason' => $reason,
        ]], $userId);
    }

    public function undoPriceAdjustment(Quotation $quotation, PriceAdjustment $adjustment, ?int $userId): Quotation
    {
        $reverted = PriceAdjustment::where('id', $adjustment->id)
            ->where('status', 'active')
            ->update([
                'status' => 'reverted',
                'reverted_at' => now(),
                'reverted_by' => $userId,
            ]);

        if ($reverted === 0) {
            throw new \Exception('This adjustment has already been reverted.');
        }

        $isGrouped = collect($quotation->extra_vehicles ?? [])->contains(fn ($ev) => isset($ev['booking_id']));
        $isEditableDraft = in_array($quotation->status, ['draft', 'pending'], true);
        $discount = (float) ($quotation->discount ?? 0);
        $newAdditionalFee = $this->netActiveAdjustment($quotation->quotation_number);

        if ($isGrouped) {
            $groupServiceTotal = collect($quotation->extra_vehicles)->sum(fn ($ev) => (float) ($ev['final_total'] ?? $ev['estimated_price'] ?? 0));
            $baseTotal = round($groupServiceTotal - $discount, 2);
        } else {
            $sourceBooking = $quotation->source_booking_id ? Booking::find($quotation->source_booking_id) : null;
            $baseTotal = $this->canonicalBaseTotalFor($sourceBooking, $isEditableDraft)
                ?? round((float) $quotation->estimated_price - (float) $quotation->additional_fee, 2);
        }

        $newPrice = max(round($baseTotal + $newAdditionalFee, 2), 0);

        $payload = [
            'estimated_price' => $newPrice,
            'additional_fee' => $newAdditionalFee,
        ];

        if ($isEditableDraft) {
            $quotation->update($payload);
            $next = $quotation->fresh();
        } else {
            $payload['status'] = in_array($quotation->status, ['sent', 'negotiating'], true) ? 'sent' : $quotation->status;
            $next = $quotation->newVersion($payload);
        }

        if (! $isGrouped && $next->source_booking_id) {
            $sourceBooking = Booking::find($next->source_booking_id);
            if ($sourceBooking) {
                $vatRate = $next->vat_rate !== null ? (float) $next->vat_rate : $this->bookingService()->vatRate();
                $totals = $this->bookingService()->applyVatAndAdjustment(
                    $this->bookingService()->taxableSubtotalFor($sourceBooking, $isEditableDraft),
                    $newAdditionalFee,
                    $vatRate,
                );
                $sourceBooking->update($this->bookingService()->filterPayloadForTable('bookings', [
                    'final_total' => $totals['final_total'],
                    'vat_amount' => $totals['vat_amount'],
                    'vat_exclusive_total' => $totals['subtotal'],
                    'quotation_id' => $next->id,
                ]));
            }
        }

        return $next;
    }
}
