<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\QuotationService;
use Illuminate\Console\Command;

class BackfillMobileBookingQuotations extends Command
{
    protected $signature = 'booking:backfill-quotations';
    protected $description = 'Create pending quotations for mobile bookings that do not have one yet';

    public function handle(QuotationService $quotationService): int
    {
        $bookings = Booking::with(['customer', 'truckType'])
            ->where('confirmation_type', 'mobile')
            ->whereNull('quotation_id')
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('No mobile bookings found needing a quotation.');
            return 0;
        }

        $this->info("Found {$bookings->count()} booking(s) to backfill.");
        $created = 0;
        $failed  = 0;

        foreach ($bookings as $booking) {
            if (! $booking->customer) {
                $this->warn("  Skipping booking #{$booking->booking_code} — no customer record.");
                $failed++;
                continue;
            }

            try {
                $distanceKm   = (float) $booking->distance_km;
                $kmIncrements = (int) floor($distanceKm / 4);
                $distanceFee  = $kmIncrements * 200;
                $estimated    = (float) ($booking->computed_total ?? ($booking->base_rate + $distanceFee));

                $quotation = $quotationService->createQuotation([
                    'source_booking_id'  => $booking->id,
                    'customer_id'        => $booking->customer->id,
                    'truck_type_id'      => $booking->truck_type_id,
                    'pickup_address'     => $booking->pickup_address,
                    'dropoff_address'    => $booking->dropoff_address,
                    'distance_km'        => $distanceKm,
                    'estimated_price'    => $estimated,
                    'service_type'       => $booking->service_type ?? 'book_now',
                    'scheduled_date'     => $booking->scheduled_date?->toDateString(),
                    'scheduled_time'     => $booking->scheduled_time,
                    'vehicle_image_path' => $booking->vehicle_image_path,
                    'extra_vehicles'     => $booking->extra_vehicles,
                    'pickup_notes'       => $booking->notes,
                ]);

                $booking->update(['quotation_id' => $quotation->id]);

                $this->line("  ✓ {$booking->booking_code} → {$quotation->quotation_number}");
                $created++;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$booking->booking_code}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Done. Created: {$created}, Failed: {$failed}.");
        return $failed > 0 ? 1 : 0;
    }
}
