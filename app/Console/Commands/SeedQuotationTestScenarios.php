<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Console\Command;

class SeedQuotationTestScenarios extends Command
{
    protected $signature = 'dev:seed-quotation-test-scenarios {--wipe-only : Delete existing TEST-Q1215-* data and exit without recreating it}';
    protected $description = 'Create (or reset) local-only Book Now, Scheduled, and grouped Scheduled test bookings, all ready for quotation, for manually testing Issues 12-15 in Dispatcher WEB';

    private const PREFIX = 'TEST-Q1215-';
    private const DISTANCE_KM = 12.5;

    public function handle(BookingService $bookingService): int
    {
        $this->wipeExisting();

        if ($this->option('wipe-only')) {
            $this->info('Wiped existing TEST-Q1215-* data. --wipe-only set, not recreating.');
            return 0;
        }

        $truckTypes = TruckType::where('status', 'active')->orderBy('id')->get();
        if ($truckTypes->isEmpty()) {
            $this->error('No active truck types found — cannot seed test bookings.');
            return 1;
        }
        $primaryTruckType = $truckTypes->first();
        $secondaryTruckType = $truckTypes->count() > 1 ? $truckTypes->get(1) : $primaryTruckType;

        $bookNowCustomer = $this->ensureTestCustomer(
            email: 'test.q1215.booknow@towmate.test',
            phone: '09990011001',
            firstName: 'Marites',
            lastName: 'Villanueva',
        );
        $scheduledCustomer = $this->ensureTestCustomer(
            email: 'test.q1215.scheduled@towmate.test',
            phone: '09990011002',
            firstName: 'Ramon',
            lastName: 'Cruz',
        );
        $groupCustomer = $this->ensureTestCustomer(
            email: 'test.q1215.group@towmate.test',
            phone: '09990011003',
            firstName: 'Ligaya',
            lastName: 'Santos',
        );

        $bookNow = $this->makeBookingShell(
            customer: $bookNowCustomer,
            bookingService: $bookingService,
            truckType: $primaryTruckType,
            groupCode: self::PREFIX . 'BOOKNOW',
            status: 'requested',
            serviceType: 'book_now',
            scheduledFor: null,
            pickupAddress: 'TEST DATA — 88 Katipunan Avenue, Quezon City, Metro Manila',
            pickupLat: 14.6323,
            pickupLng: 121.0723,
            dropoffAddress: 'TEST DATA — 21 Shaw Boulevard, Mandaluyong City, Metro Manila',
            dropoffLat: 14.5809,
            dropoffLng: 121.0509,
            vehicleMake: 'Toyota',
            vehicleModel: 'Vios',
            vehicleColor: 'Silver',
            plateNumber: 'TEST 1001',
        );

        $scheduled = $this->makeBookingShell(
            customer: $scheduledCustomer,
            bookingService: $bookingService,
            truckType: $primaryTruckType,
            groupCode: self::PREFIX . 'SCHEDULED',
            status: 'scheduled',
            serviceType: 'schedule',
            scheduledFor: now()->addDays(3)->setTime(9, 0),
            pickupAddress: 'TEST DATA — 15 Aguinaldo Highway, Bacoor, Cavite',
            pickupLat: 14.4590,
            pickupLng: 120.9366,
            dropoffAddress: 'TEST DATA — 350 EDSA, Pasay City, Metro Manila',
            dropoffLat: 14.5407,
            dropoffLng: 121.0025,
            vehicleMake: 'Honda',
            vehicleModel: 'CR-V',
            vehicleColor: 'Dark Blue',
            plateNumber: 'TEST 1002',
        );

        $groupCode = self::PREFIX . 'GROUP';
        $groupVehicleA = $this->makeBookingShell(
            customer: $groupCustomer,
            bookingService: $bookingService,
            truckType: $primaryTruckType,
            groupCode: $groupCode,
            status: 'scheduled',
            serviceType: 'schedule',
            scheduledFor: now()->addDays(4)->setTime(13, 0),
            pickupAddress: 'TEST DATA — 200 Ortigas Avenue, Pasig City, Metro Manila',
            pickupLat: 14.5866,
            pickupLng: 121.0630,
            dropoffAddress: 'TEST DATA — 77 Commonwealth Avenue, Quezon City, Metro Manila',
            dropoffLat: 14.6969,
            dropoffLng: 121.0806,
            vehicleMake: 'Ford',
            vehicleModel: 'Ranger',
            vehicleColor: 'White',
            plateNumber: 'TEST 1003',
        );
        $groupVehicleB = $this->makeBookingShell(
            customer: $groupCustomer,
            bookingService: $bookingService,
            truckType: $secondaryTruckType,
            groupCode: $groupCode,
            status: 'scheduled',
            serviceType: 'schedule',
            scheduledFor: now()->addDays(4)->setTime(13, 0),
            pickupAddress: 'TEST DATA — 200 Ortigas Avenue, Pasig City, Metro Manila',
            pickupLat: 14.5866,
            pickupLng: 121.0630,
            dropoffAddress: 'TEST DATA — 77 Commonwealth Avenue, Quezon City, Metro Manila',
            dropoffLat: 14.6969,
            dropoffLng: 121.0806,
            vehicleMake: 'Isuzu',
            vehicleModel: 'D-Max',
            vehicleColor: 'Black',
            plateNumber: 'TEST 1004',
        );

        $this->info('Created TEST-Q1215-* test bookings:');
        $this->table(
            ['Scenario', 'booking_code', 'group_code', 'status', 'service_type', 'truck_type'],
            [
                ['Book Now (single)', $bookNow->booking_code, $bookNow->group_code, $bookNow->status, $bookNow->service_type, $primaryTruckType->name],
                ['Scheduled (single)', $scheduled->booking_code, $scheduled->group_code, $scheduled->status, $scheduled->service_type, $primaryTruckType->name],
                ['Grouped Scheduled — Vehicle 1', $groupVehicleA->booking_code, $groupVehicleA->group_code, $groupVehicleA->status, $groupVehicleA->service_type, $primaryTruckType->name],
                ['Grouped Scheduled — Vehicle 2', $groupVehicleB->booking_code, $groupVehicleB->group_code, $groupVehicleB->status, $groupVehicleB->service_type, $secondaryTruckType->name],
            ],
        );

        $this->line('');
        $this->info('Book Now customer:  test.q1215.booknow@towmate.test');
        $this->info('Scheduled customer: test.q1215.scheduled@towmate.test');
        $this->info('Grouped customer:   test.q1215.group@towmate.test');
        $this->line('These are dispatcher-facing test rows only — no customer login/password is needed to view or quote them in Dispatcher WEB.');

        return 0;
    }

    private function wipeExisting(): void
    {
        $bookingIds = Booking::where('group_code', 'like', self::PREFIX . '%')->pluck('id');

        if ($bookingIds->isEmpty()) {
            return;
        }

        \App\Models\Quotation::whereIn('source_booking_id', $bookingIds)->delete();
        \App\Models\AuditLog::where('entity_type', 'Booking')->whereIn('entity_id', $bookingIds)->delete();
        Booking::whereIn('id', $bookingIds)->delete();

        $this->info("Wiped {$bookingIds->count()} previous TEST-Q1215-* booking(s) and their quotations.");
    }

    private function ensureTestCustomer(string $email, string $phone, string $firstName, string $lastName): Customer
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => "{$firstName} {$lastName}",
                'password' => 'TestQ1215!',
                'role_id' => 5,
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

    private function makeBookingShell(
        Customer $customer,
        BookingService $bookingService,
        TruckType $truckType,
        string $groupCode,
        string $status,
        string $serviceType,
        ?\Carbon\Carbon $scheduledFor,
        string $pickupAddress,
        float $pickupLat,
        float $pickupLng,
        string $dropoffAddress,
        float $dropoffLat,
        float $dropoffLng,
        string $vehicleMake,
        string $vehicleModel,
        string $vehicleColor,
        string $plateNumber,
    ): Booking {
        $baseRate = (float) $truckType->base_rate;
        $perKmRate = (float) $truckType->per_km_rate;
        $distanceFee = $bookingService->distanceFeeFor(self::DISTANCE_KM, $perKmRate);
        $computedTotal = round($baseRate + $distanceFee, 2);
        $vatRate = $bookingService->vatRate();
        $vatAmount = round($computedTotal * $vatRate, 2);

        return Booking::create(array_filter([
            'group_code' => $groupCode,
            'customer_id' => $customer->id,
            'truck_type_id' => $truckType->id,
            'status' => $status,
            'service_type' => $serviceType,
            'scheduled_date' => $scheduledFor?->toDateString(),
            'scheduled_time' => $scheduledFor?->format('H:i'),
            'pickup_address' => $pickupAddress,
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'dropoff_address' => $dropoffAddress,
            'dropoff_lat' => $dropoffLat,
            'dropoff_lng' => $dropoffLng,
            'distance_km' => self::DISTANCE_KM,
            'vehicle_make' => $vehicleMake,
            'vehicle_model' => $vehicleModel,
            'vehicle_color' => $vehicleColor,
            'vehicle_plate_number' => $plateNumber,
            'base_rate' => $baseRate,
            'per_km_rate' => $perKmRate,
            'computed_total' => $computedTotal,
            'vat_exclusive_total' => $computedTotal,
            'vat_amount' => $vatAmount,
            'notes' => 'TEST DATA seeded via php artisan dev:seed-quotation-test-scenarios — safe to delete/reset.',
        ], fn($value) => $value !== null));
    }
}
