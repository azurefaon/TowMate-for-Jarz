<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\QuotationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedTlDemoGroupTask extends Command
{
    protected $signature = 'dev:seed-tl-demo-group-task {--reset : Remove the existing demo group task instead of creating a new one} {--team-leader-email= : Email of the Team Leader to assign the demo group to (defaults to TEAMLEADER_EMAIL / teamleader@gmail.com)} {--vehicles=3 : Number of vehicles in the normalized consolidated group (2-6)}';
    protected $description = 'Create or remove a realistic normalized consolidated group booking (2-6 vehicles, already at arrived_dropoff) for visually testing the Team Leader Step 5 group-payment UI (local/dev only)';

    private const DEMO_CUSTOMER_EMAIL = 'demo.tl.group@towmate.test';
    private const DISTANCE_KM = 8.4;
    private const PICKUP_ADDRESS = '123 Commonwealth Avenue, Quezon City, Metro Manila';
    private const DROPOFF_ADDRESS = '456 Ayala Avenue, Makati City, Metro Manila';
    private const MARKER = 'DEMO GROUP TASK — created via php artisan dev:seed-tl-demo-group-task. Safe to reset or delete.';
    private const ADJUSTMENT = 200.0;

    public function handle(BookingService $bookingService, QuotationService $quotationService): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refusing to run: this command only operates in the local/testing environment.');
            return 1;
        }

        $email = strtolower((string) ($this->option('team-leader-email') ?: env('TEAMLEADER_EMAIL', 'teamleader@gmail.com')));
        $teamLeader = User::where('email', $email)->first();

        if (! $teamLeader) {
            $this->error("No Team Leader found with email '{$email}'.");
            $this->line('Run: php artisan db:seed --class=TeamLeaderSeeder');
            return 1;
        }

        if ((int) $teamLeader->role_id !== 3) {
            $this->error("User '{$email}' is not a Team Leader (role_id=3).");
            return 1;
        }

        $unit = Unit::where('team_leader_id', $teamLeader->id)->first();

        if (! $unit) {
            $this->error("Team Leader '{$teamLeader->name}' has no assigned Unit.");
            $this->line('Run: php artisan db:seed --class=UnitSeeder');
            return 1;
        }

        $existingBookings = $this->findDemoGroupBookings($teamLeader->id);

        if ($this->option('reset')) {
            if ($existingBookings->isEmpty()) {
                $this->error('No existing demo group task found to remove.');
                return 1;
            }

            $this->removeGroup($existingBookings);
            $this->info('Demo group task removed.');
            return 0;
        }

        $vehicles = (int) $this->option('vehicles');
        if ($vehicles < 2 || $vehicles > 6) {
            $this->error('--vehicles must be between 2 and 6.');
            return 1;
        }

        if ($existingBookings->isNotEmpty()) {
            $this->removeGroup($existingBookings);
        }

        $this->clearStaleDemoBookingsForTeamLeader($teamLeader->id, self::DEMO_CUSTOMER_EMAIL);

        $truckType = $unit->truckType;
        if (! $truckType) {
            $this->error("Unit '{$unit->name}' has no truck type configured.");
            return 1;
        }

        $customer = Customer::firstOrCreate(
            ['email' => self::DEMO_CUSTOMER_EMAIL],
            [
                'full_name' => 'Maria Santos',
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'phone' => '09179876543',
            ],
        );

        $priced = $bookingService->priceOneVehicle((float) $truckType->base_rate, self::DISTANCE_KM, (float) $truckType->per_km_rate);
        $computedTotal = round($priced['base_rate'] + $priced['distance_fee'], 2);
        $groupCode = 'DEMOGRP-' . uniqid();

        [$bookings, $primary] = DB::transaction(function () use ($vehicles, $customer, $truckType, $unit, $teamLeader, $groupCode, $priced, $computedTotal, $quotationService) {
            $bookings = collect();
            for ($i = 0; $i < $vehicles; $i++) {
                $booking = Booking::create([
                    'customer_id' => $customer->id,
                    'truck_type_id' => $truckType->id,
                    'assigned_unit_id' => $unit->id,
                    'assigned_team_leader_id' => $teamLeader->id,
                    'group_code' => $groupCode,
                    'service_type' => 'book_now',
                    'pickup_address' => self::PICKUP_ADDRESS,
                    'pickup_lat' => 14.6760,
                    'pickup_lng' => 121.0437,
                    'dropoff_address' => self::DROPOFF_ADDRESS,
                    'dropoff_lat' => 14.5547,
                    'dropoff_lng' => 121.0244,
                    'distance_km' => self::DISTANCE_KM,
                    'base_rate' => $priced['base_rate'],
                    'per_km_rate' => $truckType->per_km_rate,
                    'computed_total' => $computedTotal,
                    'vat_exclusive_total' => $computedTotal,
                    'vat_amount' => $priced['vat_amount'],
                    'final_total' => $priced['final_total'],
                    'notes' => 'Consolidated group tow — vehicles staged together for flatbed transport.',
                    'dispatcher_note' => self::MARKER,
                    'status' => 'arrived_dropoff',
                    'assigned_at' => now(),
                ]);
                $booking->timestamps = false;
                $booking->forceFill([
                    'created_at' => now()->addSeconds($i),
                    'updated_at' => now()->addSeconds($i),
                ])->save();
                $bookings->push($booking);
            }

            $primary = $bookings->first();
            $extraVehicles = $bookings->map(fn (Booking $booking) => [
                'booking_id' => $booking->id,
                'truck_type_name' => $truckType->name,
                'final_total' => (float) $priced['final_total'],
            ])->values()->all();

            $estimatedPrice = round(($vehicles * $priced['final_total']) + self::ADJUSTMENT, 2);

            $quotation = $quotationService->createQuotation([
                'source_booking_id' => $primary->id,
                'customer_id' => $customer->id,
                'truck_type_id' => $truckType->id,
                'pickup_address' => self::PICKUP_ADDRESS,
                'dropoff_address' => self::DROPOFF_ADDRESS,
                'distance_km' => self::DISTANCE_KM,
                'extra_vehicles' => $extraVehicles,
                'estimated_price' => $estimatedPrice,
                'service_type' => 'book_now',
            ]);

            $quotation->update([
                'additional_fee' => self::ADJUSTMENT,
                'discount' => 0,
                'status' => 'accepted',
            ]);

            foreach ($bookings as $booking) {
                $booking->update(['quotation_id' => $quotation->id]);
            }

            return [$bookings, $primary];
        });

        $this->info("Created demo group task {$groupCode} ({$vehicles} vehicles) for {$teamLeader->name}, status=arrived_dropoff.");
        $this->line('Primary booking: ' . $primary->booking_code);
        $this->line('Open the Flutter Team Leader app, sign in, and tap Proceed to Verification on Step 4 to preview the Step 5 Group Payment UI.');
        return 0;
    }

    private function findDemoGroupBookings(int $teamLeaderId): \Illuminate\Support\Collection
    {
        $customer = Customer::where('email', self::DEMO_CUSTOMER_EMAIL)->first();
        if (! $customer) {
            return collect();
        }

        return Booking::where('customer_id', $customer->id)
            ->where('assigned_team_leader_id', $teamLeaderId)
            ->get();
    }

    private function removeGroup(\Illuminate\Support\Collection $bookings): void
    {
        $bookingIds = $bookings->pluck('id');

        Invoice::whereIn('booking_id', $bookingIds)->delete();
        Quotation::whereIn('source_booking_id', $bookingIds)->delete();
        Booking::whereIn('id', $bookingIds)->delete();
    }

    private function clearStaleDemoBookingsForTeamLeader(int $teamLeaderId, string $ownDemoCustomerEmail): void
    {
        $nonTerminalStatuses = [
            'assigned', 'accepted', 'on_the_way', 'arrived_pickup',
            'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff',
            'waiting_verification',
        ];

        $stale = Booking::where('assigned_team_leader_id', $teamLeaderId)
            ->whereIn('status', $nonTerminalStatuses)
            ->where('dispatcher_note', 'like', 'DEMO%')
            ->whereHas('customer', fn ($q) => $q->where('email', '!=', $ownDemoCustomerEmail))
            ->get();

        foreach ($stale as $booking) {
            Invoice::where('booking_id', $booking->id)->delete();
            Quotation::where('source_booking_id', $booking->id)->delete();
            if ($booking->assigned_unit_id) {
                Unit::whereKey($booking->assigned_unit_id)->update(['status' => 'available']);
            }
            $booking->delete();
        }
    }
}
