<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\User;
use App\Services\BookingService;
use App\Services\QuotationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * DEV/TEST-ONLY data generator for the 9 Scheduled dispatch-queue scenarios
 * used in manual runtime testing. Every row this command creates is tagged
 * with a `group_code` starting "TEST-SCHED-" — that prefix is the single
 * source of truth this command uses to find and safely wipe its own data on
 * every run, so it never touches a real booking/quotation. Not wired into
 * any route, controller, or scheduled job — run manually only.
 */
class SeedScheduledTestBookings extends Command
{
    protected $signature = 'dev:seed-scheduled-test-data {--wipe-only : Delete existing TEST-SCHED-* data and exit without recreating it}';
    protected $description = 'Create (or reset) the 9 TEST-SCHED-* Scheduled booking scenarios used for manual runtime testing';

    private const PREFIX = 'TEST-SCHED-';
    private const TRUCK_TYPE_ID = 15; // Light Duty — matches units 37 (Sampaguita) & 38 (Ylang-Ylang), both already online
    private const DISTANCE_KM = 12.5;

    public function handle(QuotationService $quotationService, BookingService $bookingService): int
    {
        $this->wipeExisting();

        if ($this->option('wipe-only')) {
            $this->info('Wiped existing TEST-SCHED-* data. --wipe-only set, not recreating.');
            return 0;
        }

        $mainCustomer = $this->ensureTestCustomer(
            email: 'test.scheduled@towmate.test',
            phone: '09990001111',
            firstName: 'TEST-SCHED',
            lastName: 'Customer',
        );
        $priceReviewCustomer = $this->ensureTestCustomer(
            email: 'test.scheduled.pricereview@towmate.test',
            phone: '09990002222',
            firstName: 'TEST-SCHED-PRICEREVIEW',
            lastName: 'Customer',
        );

        $now = now();
        $rows = [];

        // 1. Needs Quote — no quotation at all yet.
        $b1 = $this->makeBookingShell($mainCustomer, $bookingService, self::PREFIX . 'NEEDS-QUOTE', 'scheduled', $now->copy()->addDays(5)->setTime(14, 0));
        $rows[] = ['label' => 'Needs Quote', 'booking' => $b1];

        // 2. Draft — quotation exists, status=draft, never sent.
        $b2 = $this->makeBookingShell($mainCustomer, $bookingService, self::PREFIX . 'DRAFT', 'scheduled', $now->copy()->addDays(5)->setTime(15, 0));
        $q2 = $this->makeQuotation($quotationService, $bookingService, $b2, 'draft');
        $b2->update(['quotation_number' => $q2->quotation_number, 'quotation_generated' => true, 'final_total' => $q2->estimated_price]);
        $rows[] = ['label' => 'Draft', 'booking' => $b2, 'quotation' => $q2];

        // 3. Quote Sent — far from the 2h cutoff (5 days out).
        $b3 = $this->makeBookingShell($mainCustomer, $bookingService, self::PREFIX . 'SENT', 'scheduled', $now->copy()->addDays(5)->setTime(16, 0));
        $q3 = $this->makeQuotation($quotationService, $bookingService, $b3, 'sent');
        $b3->update(['quotation_number' => $q3->quotation_number, 'quotation_generated' => true, 'final_total' => $q3->estimated_price]);
        $rows[] = ['label' => 'Quote Sent', 'booking' => $b3, 'quotation' => $q3];

        // 4. Confirmed — accepted, >24h out, no unit.
        $b4 = $this->makeAcceptedBooking($mainCustomer, $quotationService, $bookingService, self::PREFIX . 'CONFIRMED', $now->copy()->addDays(3));
        $rows[] = ['label' => 'Confirmed', 'booking' => $b4];

        // 5. Upcoming — accepted, between 1h and 24h out.
        $b5 = $this->makeAcceptedBooking($mainCustomer, $quotationService, $bookingService, self::PREFIX . 'UPCOMING', $now->copy()->addHours(12));
        $rows[] = ['label' => 'Upcoming', 'booking' => $b5];

        // 6. Ready A — accepted, within the next 1h.
        $b6 = $this->makeAcceptedBooking($mainCustomer, $quotationService, $bookingService, self::PREFIX . 'READY-A', $now->copy()->addMinutes(30));
        $rows[] = ['label' => 'Ready A', 'booking' => $b6];

        // 7. Ready B — same truck class as Ready A, also within the next 1h, for the same-unit race test.
        $b7 = $this->makeAcceptedBooking($mainCustomer, $quotationService, $bookingService, self::PREFIX . 'READY-B', $now->copy()->addMinutes(40));
        $rows[] = ['label' => 'Ready B', 'booking' => $b7];

        // 8. Overdue — accepted, already past.
        $b8 = $this->makeAcceptedBooking($mainCustomer, $quotationService, $bookingService, self::PREFIX . 'OVERDUE', $now->copy()->subMinutes(15));
        $rows[] = ['label' => 'Overdue', 'booking' => $b8];

        // 9. Price Review — sent, own dedicated customer (Flutter "pending quotation" only surfaces
        //    the latest `sent` quotation per customer — a shared customer would collide with #3).
        $b9 = $this->makeBookingShell($priceReviewCustomer, $bookingService, self::PREFIX . 'PRICE-REVIEW', 'scheduled', $now->copy()->addDays(5)->setTime(17, 0));
        $q9 = $this->makeQuotation($quotationService, $bookingService, $b9, 'sent');
        $b9->update(['quotation_number' => $q9->quotation_number, 'quotation_generated' => true, 'final_total' => $q9->estimated_price]);
        $rows[] = ['label' => 'Price Review', 'booking' => $b9, 'quotation' => $q9];

        $this->info('Created ' . count($rows) . ' TEST-SCHED-* Scheduled bookings:');
        $this->table(
            ['Scenario', 'booking_code', 'group_code', 'status', 'scheduled_for'],
            collect($rows)->map(fn($r) => [
                $r['label'],
                $r['booking']->booking_code,
                $r['booking']->group_code,
                $r['booking']->status,
                $r['booking']->fresh()->scheduled_for?->format('Y-m-d H:i'),
            ])->all(),
        );

        $this->line('');
        $this->info('Main test customer login: test.scheduled@towmate.test / TestSched123!');
        $this->info('Price Review test customer login: test.scheduled.pricereview@towmate.test / TestSched123!');
        $this->line('Both use role_id=5 (Customer), status=active.');
        $this->line('');
        $this->info('Ready A/B share truck_type_id=15 (Light Duty) — Unit 37 (Sampaguita/Jun Dela Cruz) and Unit 38 (Ylang-Ylang/Bea Fernandez) are already seeded, status=available, and always "online" for dispatch testing.');

        return 0;
    }

    private function wipeExisting(): void
    {
        $bookingIds = Booking::where('group_code', 'like', self::PREFIX . '%')->pluck('id');

        if ($bookingIds->isEmpty()) {
            return;
        }

        Quotation::whereIn('source_booking_id', $bookingIds)->delete();
        AuditLog::where('entity_type', 'Booking')->whereIn('entity_id', $bookingIds)->delete();
        Booking::whereIn('id', $bookingIds)->delete();

        $this->info("Wiped {$bookingIds->count()} previous TEST-SCHED-* booking(s) and their quotations.");
    }

    private function ensureTestCustomer(string $email, string $phone, string $firstName, string $lastName): Customer
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => "{$firstName} {$lastName}",
                'password' => 'TestSched123!', // User model casts password => 'hashed'
                'role_id' => 5, // Customer
                'status' => 'active',
            ],
        );

        return Customer::firstOrCreate(
            ['user_id' => $user->id],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'email' => $email,
            ],
        );
    }

    /**
     * Mirrors CustomerBookingController::store()'s pricing fields at booking
     * creation time exactly — same formula (BookingService::distanceFeeFor(),
     * first 4 km included), same rounding, same column set (base_rate,
     * per_km_rate, computed_total, vat_exclusive_total, vat_amount) — read
     * live from the TruckType row, never hardcoded. Without this, a seeded
     * booking looked visually different from a real customer-submitted one
     * (₱0.00 base rate) before any dispatcher had touched it.
     */
    private function makeBookingShell(Customer $customer, BookingService $bookingService, string $groupCode, string $status, Carbon $scheduledFor): Booking
    {
        $baseRate = self::baseRateFor(self::TRUCK_TYPE_ID);
        $perKmRate = self::perKmRateFor(self::TRUCK_TYPE_ID);
        $distanceFee = $bookingService->distanceFeeFor(self::DISTANCE_KM, $perKmRate);
        $computedTotal = round($baseRate + $distanceFee, 2);
        $vatAmount = round($computedTotal * 0.12, 2);

        return Booking::create([
            'group_code' => $groupCode,
            'customer_id' => $customer->id,
            'truck_type_id' => self::TRUCK_TYPE_ID,
            'status' => $status,
            'service_type' => 'schedule',
            'scheduled_date' => $scheduledFor->toDateString(),
            'scheduled_time' => $scheduledFor->format('H:i'),
            'pickup_address' => 'TEST DATA — 123 Test Street, Quezon City, Metro Manila',
            'pickup_lat' => 14.6760,
            'pickup_lng' => 121.0437,
            'dropoff_address' => 'TEST DATA — 456 Sample Avenue, Makati City, Metro Manila',
            'dropoff_lat' => 14.5547,
            'dropoff_lng' => 121.0244,
            'distance_km' => self::DISTANCE_KM,
            'base_rate' => $baseRate,
            'per_km_rate' => $perKmRate,
            'computed_total' => $computedTotal,
            'vat_exclusive_total' => $computedTotal,
            'vat_amount' => $vatAmount,
            'notes' => 'TEST DATA seeded via php artisan dev:seed-scheduled-test-data — safe to delete/reset.',
        ]);
    }

    /** Draft/Sent quotation tied to $booking, using the app's own pricing formula. */
    private function makeQuotation(QuotationService $quotationService, BookingService $bookingService, Booking $booking, string $status): Quotation
    {
        $baseRate = self::baseRateFor(self::TRUCK_TYPE_ID);
        $perKmRate = self::perKmRateFor(self::TRUCK_TYPE_ID);
        $distanceFee = $bookingService->distanceFeeFor(self::DISTANCE_KM, $perKmRate);
        $estimatedPrice = round(($baseRate + $distanceFee) * 1.12, 2);

        $quotation = $quotationService->createQuotation([
            'source_booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'truck_type_id' => $booking->truck_type_id,
            'pickup_address' => $booking->pickup_address,
            'dropoff_address' => $booking->dropoff_address,
            'distance_km' => self::DISTANCE_KM,
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Hilux',
            'vehicle_year' => '2020',
            'vehicle_color' => 'White',
            'vehicle_plate_number' => 'TEST 1234',
            'estimated_price' => $estimatedPrice,
            'service_type' => 'schedule',
            'scheduled_date' => $booking->scheduled_date?->toDateString(),
            'scheduled_time' => $booking->scheduled_time,
            'pickup_notes' => $booking->notes,
        ]);

        if ($status === 'draft') {
            $quotation->update(['status' => 'draft']);
        } elseif ($status === 'sent') {
            $quotationService->sendQuotation($quotation, 168);
        }

        return $quotation->fresh();
    }

    /**
     * A fully accepted Scheduled booking — status=scheduled_confirmed, mirrors
     * QuotationService::acceptQuotation()'s field set. Deliberately does NOT
     * route through QuotationService::sendQuotation() — that applies the live
     * scheduled_for - 2h cutoff, which correctly refuses to "send" a quote
     * for a booking whose scheduled_for is already at/near Ready or Overdue
     * (exactly as it should for a real live send). This is fabricated
     * already-resolved history, not a live send action being tested, so the
     * quotation is created and marked accepted directly.
     */
    private function makeAcceptedBooking(Customer $customer, QuotationService $quotationService, BookingService $bookingService, string $groupCode, Carbon $scheduledFor): Booking
    {
        $booking = $this->makeBookingShell($customer, $bookingService, $groupCode, 'scheduled', $scheduledFor);

        $baseRate = self::baseRateFor(self::TRUCK_TYPE_ID);
        $perKmRate = self::perKmRateFor(self::TRUCK_TYPE_ID);
        $distanceFee = $bookingService->distanceFeeFor(self::DISTANCE_KM, $perKmRate);
        $estimatedPrice = round(($baseRate + $distanceFee) * 1.12, 2);

        $quotation = $quotationService->createQuotation([
            'source_booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'truck_type_id' => $booking->truck_type_id,
            'pickup_address' => $booking->pickup_address,
            'dropoff_address' => $booking->dropoff_address,
            'distance_km' => self::DISTANCE_KM,
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Hilux',
            'vehicle_year' => '2020',
            'vehicle_color' => 'White',
            'vehicle_plate_number' => 'TEST 1234',
            'estimated_price' => $estimatedPrice,
            'service_type' => 'schedule',
            'scheduled_date' => $booking->scheduled_date?->toDateString(),
            'scheduled_time' => $booking->scheduled_time,
            'pickup_notes' => $booking->notes,
        ]);

        $finalTotal = (float) $quotation->estimated_price;
        $vatExclusive = round($finalTotal / 1.12, 2);
        $vatAmount = round($finalTotal - $vatExclusive, 2);

        $quotation->update([
            'status' => 'accepted',
            'sent_at' => now()->subDay(),
            'responded_at' => now(),
        ]);

        $booking->update([
            'status' => 'scheduled_confirmed',
            'quotation_id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'quotation_generated' => true,
            'final_total' => $finalTotal,
            'vat_amount' => $vatAmount,
            'vat_exclusive_total' => $vatExclusive,
            'customer_approved_at' => now(),
            'price_locked_at' => now(),
            'selected_unit_id' => null,
            'assigned_unit_id' => null,
            'assigned_team_leader_id' => null,
        ]);

        return $booking->fresh();
    }

    private static function baseRateFor(int $truckTypeId): float
    {
        return (float) (DB::table('truck_types')->where('id', $truckTypeId)->value('base_rate') ?? 0);
    }

    private static function perKmRateFor(int $truckTypeId): float
    {
        return (float) (DB::table('truck_types')->where('id', $truckTypeId)->value('per_km_rate') ?? 0);
    }
}
