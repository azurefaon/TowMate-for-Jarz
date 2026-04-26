<?php

namespace App\Services;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;

class QuotationService
{
    /**
     * Generate unique quotation number (QT-YYYYMMDD-XXXX)
     */
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

    /**
     * Create new quotation from customer request
     */
    public function createQuotation(array $data): Quotation
    {
        return Quotation::create([
            'quotation_number' => $this->generateQuotationNumber(),
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
            'estimated_price' => $data['estimated_price'],
            'service_type' => $data['service_type'] ?? null,
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

    /**
     * Get active quotation for customer
     */
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

    //  Send quotation to customer sa dispatcher 
    // int $expiryHours = 168
    public function sendQuotation(Quotation $quotation, float $expiryHours = 0.00556): Quotation
    {
        $quotation->update([
            'status' => 'sent',
            'sent_at' => now(),
            'expires_at' => now()->addHours($expiryHours),
            'expiry_hours' => $expiryHours,
        ]);

        // Send email with link to customer quotation view page
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

        return $quotation->fresh();
    }

    /**
     * Accept quotation and create booking
     */
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

            $booking = Booking::create([
                'quotation_id' => $quotation->id,
                'customer_id' => $quotation->customer_id,
                'truck_type_id' => $quotation->truck_type_id,
                'pickup_address' => $quotation->pickup_address,
                'dropoff_address' => $quotation->dropoff_address,
                'pickup_notes' => $quotation->pickup_notes,
                'distance_km' => $quotation->distance_km,
                'eta_minutes' => $quotation->eta_minutes,
                'vehicle_image_path' => $quotation->vehicle_image_path,
                'final_total' => $quotation->estimated_price,
                'status' => 'confirmed',
                'customer_approved_at' => now(),
                'price_locked_at' => now(),
            ]);

            DB::commit();

            $booking->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
            event(new BookingStatusUpdated($booking));

            return $booking;
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
            // Mail::to($quotation->customer->email)->send(new QuotationFollowUpMail($quotation));

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

    /**
     * Update quotation price
     */
    public function updateQuotationPrice(Quotation $quotation, float $newPrice): Quotation
    {
        $quotation->update([
            'estimated_price' => $newPrice,
            'counter_offer_amount' => null,
        ]);

        $quotation->increment('link_version');

        // pag nag send na, extend expiry and notify customer
        if ($quotation->status === 'sent') {
            $quotation->update([
                'sent_at' => now(),
                'expires_at' => now()->addHours($quotation->expiry_hours),
            ]);
        }

        return $quotation->fresh();
    }

    /**
     * Extend quotation expiry
     */
    public function extendQuotation(Quotation $quotation, int $additionalHours = 24): Quotation
    {
        if ($quotation->expires_at) {
            $newExpiry = $quotation->isExpired()
                ? now()->addHours($additionalHours)
                : $quotation->expires_at->addHours($additionalHours);

            $quotation->update([
                'expires_at' => $newExpiry,
                'status' => 'sent', // Reactivate if expired
            ]);
        }

        return $quotation->fresh();
    }

    /**
     * Renew expired quotation (generate fresh one)
     */
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
            'estimated_price' => $oldQuotation->estimated_price,
        ]);
    }

}
