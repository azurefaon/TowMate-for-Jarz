<?php

namespace App\Services;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Models\Quotation;
use App\Models\TruckType;
use Illuminate\Support\Facades\DB;

class QuotationService
{
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
            'service_type' => $data['service_type'] ?? null,
            'scheduled_date' => $data['scheduled_date'] ?? null,
            'scheduled_time' => $data['scheduled_time'] ?? null,
            'status' => 'pending',
        ]);
    }

    public function hasActiveQuotation(int $customerId): bool
    {
        return Quotation::where('customer_id', $customerId)
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
        $quotation->update([
            'status' => 'sent',
            'sent_at' => now(),
            'expires_at' => now()->addHours($expiryHours),
            'expiry_hours' => $expiryHours,
        ]);

        // Mobile bookings (source_booking_id set) get in-app notification; skip email.
        if (!$quotation->source_booking_id && $quotation->customer && $quotation->customer->email) {
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

        return $quotation->fresh();
    }

    public function acceptQuotation(Quotation $quotation, ?string $note = null): Booking
    {
        DB::beginTransaction();

        try {
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

            // If this quotation originated from a mobile booking, update that booking
            // rather than creating a duplicate.
            $finalTotal   = (float) $quotation->estimated_price;
            $vatExclusive = round($finalTotal / 1.12, 2);
            $vatAmount    = round($finalTotal - $vatExclusive, 2);

            if ($quotation->source_booking_id) {
                $primaryBooking = Booking::findOrFail($quotation->source_booking_id);
                $primaryBooking->update([
                    'quotation_id'         => $quotation->id,
                    'final_total'          => $finalTotal,
                    'vat_amount'           => $vatAmount,
                    'vat_exclusive_total'  => $vatExclusive,
                    'status'               => $isScheduled ? 'scheduled_confirmed' : 'confirmed',
                    'customer_approved_at' => now(),
                    'price_locked_at'      => now(),
                ]);
            } else {
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
                DB::table('booking_capacity')
                    ->updateOrInsert(
                        ['booking_date' => $date],
                        ['slots_used' => DB::raw('slots_used + 1'), 'updated_at' => now()]
                    );
            }

            $extraVehicles = $quotation->extra_vehicles ?? [];

            foreach ($extraVehicles as $ev) {
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

                $evBooking = Booking::create([
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
                    DB::table('booking_capacity')
                        ->updateOrInsert(
                            ['booking_date' => $evDate],
                            ['slots_used' => DB::raw('slots_used + 1'), 'updated_at' => now()]
                        );
                }
            }

            DB::commit();

            $primaryBooking->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
            BookingStatusUpdated::safeFire($primaryBooking);

            if ($primaryBooking->customer && $primaryBooking->customer->email) {
                try {
                    \Illuminate\Support\Facades\Mail::to($primaryBooking->customer->email)
                        ->send(new \App\Mail\FinalQuotationConfirmedMail($primaryBooking));
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to send booking confirmation email', [
                        'booking_id' => $primaryBooking->id,
                        'customer_email' => $primaryBooking->customer->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $primaryBooking;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function rejectQuotation(Quotation $quotation, ?string $reason = null): void
    {
        $quotation->update([
            'status' => 'rejected',
            'responded_at' => now(),
            'response_note' => $reason,
        ]);

        if ($quotation->source_booking_id) {
            $booking = Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader'])
                ->find($quotation->source_booking_id);
            if ($booking) {
                BookingStatusUpdated::safeFire($booking);
            }
        }
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
            $quotation->update(['follow_up_sent_at' => now()]);
            $count++;
        }

        return $count;
    }

    public function getQuotationsNeedingFollowUp()
    {
        $followUpDate = now()->subDays(5);

        return Quotation::where('status', 'sent')
            ->where('sent_at', '<=', $followUpDate)
            ->whereNull('responded_at')
            ->whereNull('follow_up_sent_at')
            ->where('expires_at', '>', now())
            ->with(['customer', 'truckType'])
            ->get();
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
                'expires_at' => now()->addHours($quotation->expiry_hours),
            ]);
        }

        return $quotation->fresh();
    }

    public function extendQuotation(Quotation $quotation, int $additionalHours = 24): Quotation
    {
        if ($quotation->expires_at) {
            $newExpiry = $quotation->isExpired()
                ? now()->addHours($additionalHours)
                : $quotation->expires_at->addHours($additionalHours);

            $quotation->update([
                'expires_at' => $newExpiry,
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
        ]);
    }
}
