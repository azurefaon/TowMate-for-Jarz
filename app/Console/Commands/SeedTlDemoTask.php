<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Console\Command;

class SeedTlDemoTask extends Command
{
    protected $signature = 'dev:seed-tl-demo-task {--reset : Reset the existing demo task back to its initial assigned state instead of creating a new one} {--team-leader-email= : Email of the Team Leader to assign the demo task to (defaults to TEAMLEADER_EMAIL / teamleader@gmail.com)}';
    protected $description = 'Create or reset a single realistic assigned demo booking for manually walking through the Team Leader task lifecycle (local/dev only)';

    private const DEMO_CUSTOMER_EMAIL = 'demo.tl.task@towmate.test';
    private const DISTANCE_KM = 8.4;

    public function handle(BookingService $bookingService): int
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

        $existing = $this->findDemoBooking($teamLeader->id);

        if ($this->option('reset')) {
            if (! $existing) {
                $this->error('No existing demo task found to reset. Run without --reset first.');
                return 1;
            }

            $this->resetBooking($existing);
            $this->info("Demo task {$existing->booking_code} reset to status=assigned.");
            return 0;
        }

        if ($existing) {
            $this->info("Demo task already exists: {$existing->booking_code} (status={$existing->status}).");
            $this->line('Use --reset to return it to its initial assigned state.');
            return 0;
        }

        $this->clearStaleDemoBookingsForTeamLeader($teamLeader->id, self::DEMO_CUSTOMER_EMAIL);

        $customer = Customer::firstOrCreate(
            ['email' => self::DEMO_CUSTOMER_EMAIL],
            [
                'full_name' => 'Juan Dela Cruz',
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'phone' => '09171234567',
            ],
        );

        $truckType = $unit->truckType;
        if (! $truckType) {
            $this->error("Unit '{$unit->name}' has no truck type configured.");
            return 1;
        }

        $priced = $bookingService->priceOneVehicle((float) $truckType->base_rate, self::DISTANCE_KM, (float) $truckType->per_km_rate);
        $computedTotal = round($priced['base_rate'] + $priced['distance_fee'], 2);

        $booking = Booking::create([
            'customer_id' => $customer->id,
            'truck_type_id' => $truckType->id,
            'assigned_unit_id' => $unit->id,
            'assigned_team_leader_id' => $teamLeader->id,
            'service_type' => 'book_now',
            'pickup_address' => '123 Commonwealth Avenue, Quezon City, Metro Manila',
            'pickup_lat' => 14.6760,
            'pickup_lng' => 121.0437,
            'dropoff_address' => '456 Ayala Avenue, Makati City, Metro Manila',
            'dropoff_lat' => 14.5547,
            'dropoff_lng' => 121.0244,
            'distance_km' => self::DISTANCE_KM,
            'base_rate' => $priced['base_rate'],
            'per_km_rate' => $truckType->per_km_rate,
            'computed_total' => $computedTotal,
            'vat_exclusive_total' => $computedTotal,
            'vat_amount' => $priced['vat_amount'],
            'final_total' => $priced['final_total'],
            'notes' => "Vehicle won't start, needs flatbed towing to the nearest service center.",
            'dispatcher_note' => 'DEMO TASK — created via php artisan dev:seed-tl-demo-task. Safe to reset or delete.',
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        $this->info("Created demo task {$booking->booking_code} for {$teamLeader->name}, status=assigned.");
        $this->line('Open the Flutter Team Leader app and sign in to accept and work through it.');
        return 0;
    }

    private function findDemoBooking(int $teamLeaderId): ?Booking
    {
        $customer = Customer::where('email', self::DEMO_CUSTOMER_EMAIL)->first();
        if (! $customer) {
            return null;
        }

        return Booking::where('customer_id', $customer->id)
            ->where('assigned_team_leader_id', $teamLeaderId)
            ->latest('id')
            ->first();
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
            \App\Models\Quotation::where('source_booking_id', $booking->id)->delete();
            if ($booking->assigned_unit_id) {
                Unit::whereKey($booking->assigned_unit_id)->update(['status' => 'available']);
            }
            $booking->delete();
        }
    }

    private function resetBooking(Booking $booking): void
    {
        Invoice::where('booking_id', $booking->id)->delete();

        $booking->update([
            'status' => 'assigned',
            'assigned_at' => now(),
            'completed_at' => null,
            'completion_requested_at' => null,
            'customer_verified_at' => null,
            'customer_verification_status' => null,
            'customer_verification_note' => null,
            'returned_at' => null,
            'return_reason' => null,
            'returned_by_team_leader_id' => null,
            'arrival_photo_path' => null,
            'dropoff_photo_path' => null,
            'customer_signature_path' => null,
            'payment_method' => null,
            'payment_proof_path' => null,
            'payment_submitted_at' => null,
            'cash_received' => null,
        ]);

        if ($booking->assigned_unit_id) {
            Unit::whereKey($booking->assigned_unit_id)->update(['status' => 'available']);
        }
    }
}
