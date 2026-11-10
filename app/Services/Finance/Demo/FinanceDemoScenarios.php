<?php

namespace App\Services\Finance\Demo;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Finance\ClientReviewItem;
use App\Models\Finance\CommissionPolicy;
use App\Models\PaymentTransaction;
use App\Models\Personnel;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\Finance\ClientReviewService;
use App\Services\Finance\CommissionPolicyService;
use App\Services\Finance\CommissionStatementService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\JobCrewService;
use App\Services\Finance\PayoutService;
use App\Services\Finance\UnitFundService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Builds the Owner Finance demonstration from the REAL Finance services (no figures are written directly).
 * Runs only where FinanceDemoGuard allows it: a separate "*_demo" database (or the automated test database).
 *
 * Scenarios (all serviced on Wednesday of last week, so they fall in one Monday–Saturday week):
 *   1. Baseline: 15,000 collected; 3,000 funds issued; 1,700 + 1,000 + 250 = 2,950 expenses; 50 returned;
 *      base 12,050 → Company 7,230, crew 4,820. Approved under the DEMO stand-in policy, two simulated payouts.
 *   2. Missing Rider: 12,050 job with no Rider → 1,205 unallocated, blocked. Also a Team Leader expense awaiting review.
 *   3. Partial payment: 7,500 verified of 15,000 → not approvable.
 *   4. Two-unit booking: another unit helped → allocation pending review; a 50:50 split is recorded ONLY as a client
 *      review proposal, never applied.
 *   5. Personal advance: 2,000 paid personally by the crew → needs clarification, blocked.
 *   6. One booking with three vehicles towed by two units.
 *
 * The active commission policy in the demo is a clearly labelled DEMONSTRATION stand-in so that statements and
 * simulated payouts can be shown. The real policy v1 stays "pending client confirmation".
 */
class FinanceDemoScenarios
{
    public const POLICY_NAME = 'DEMONSTRATION ONLY — stand-in for pending client decisions';
    public const STAND_IN = 'demo_stand_in';

    private User $owner;
    private User $dispatcher;
    private CarbonImmutable $day;
    private string $password;
    private int $seq = 0;

    public function __construct(
        private readonly JobCrewService $crew,
        private readonly ExpenseService $expenses,
        private readonly UnitFundService $funds,
        private readonly CommissionPolicyService $policies,
        private readonly CommissionStatementService $statements,
        private readonly PayoutService $payouts,
        private readonly ClientReviewService $review,
    ) {
    }

    /** @return array{owner_email:string, password:string, scenarios:array<string,array>} */
    public function seed(?string $password = null): array
    {
        FinanceDemoGuard::assertCanSeed();

        $this->password = $password ?: (string) (config('finance.demo.password') ?: Str::password(16, symbols: false));
        $this->day = CarbonImmutable::now(config('finance.timezone'))->startOfWeek(CarbonImmutable::MONDAY)->subWeek()->addDays(2)->setTime(10, 0);
        foreach ([1 => 'Owner', 2 => 'Admin', 3 => 'Team Leader', 4 => 'Driver', 5 => 'Customer', 6 => 'System Admin'] as $id => $name) {
            if (! Role::find($id)) {
                $role = new Role(['name' => $name]);
                $role->id = $id;
                $role->save();
            }
        }
        $this->owner = $this->user(1, (string) config('finance.demo.owner_email'), 'Demo', 'Owner');
        $this->dispatcher = $this->user(2, 'demo.dispatcher@towmate.test', 'Demo', 'Dispatcher');

        $policy = $this->standInPolicy();
        $s = [];
        $s['baseline'] = $this->baseline();
        $s['missing_rider'] = $this->missingRider();
        $s['partial_payment'] = $this->partialPayment();
        $s['two_unit'] = $this->twoUnit();
        $s['personal_advance'] = $this->personalAdvance();
        $s['three_vehicles'] = $this->threeVehicles();

        return ['owner_email' => $this->owner->email, 'password' => $this->password, 'policy_version' => $policy->version, 'week_start' => $this->day->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), 'scenarios' => $s];
    }

    // ------------------------------------------------------------------ scenarios

    private function baseline(): array
    {
        [$unit, $leader] = $this->unit('DEMO Unit 1 — Baseline');
        $booking = $this->booking($unit, '15000.00', 'Baseline Customer');
        $people = $this->fullCrew($booking, $unit, $leader);
        $this->funds->issue($this->owner, ['unit_id' => $unit->id, 'issue_kind' => 'initial', 'amount' => '3000.00', 'entry_date' => $this->day->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            'counterparty_name' => $leader->name, 'external_reference' => 'DEMO-FUND-001', 'notes' => 'Demonstration record'], (string) Str::uuid());
        $this->approvedExpense($booking, $unit, $leader, 'fuel_diesel', '1700.00', 'Diesel (demonstration)');
        $this->approvedExpense($booking, $unit, $leader, 'food_travel_allowance', '1000.00', 'Crew meals (demonstration)');
        $this->approvedExpense($booking, $unit, $leader, 'toll', '250.00', 'NLEX toll and parking (demonstration)');
        $this->funds->recordReturn($this->owner, ['unit_id' => $unit->id, 'amount' => '50.00', 'entry_date' => $this->day->addDay()->toDateString(), 'counterparty_name' => $leader->name,
            'external_reference' => 'DEMO-RETURN-001', 'notes' => 'Demonstration record'], (string) Str::uuid());

        $statement = $this->statements->approve($this->owner, $this->statements->submitForReview($this->owner,
            $this->statements->generate($this->owner, $this->day->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), $unit->id)));
        $this->payouts->record($this->owner, $statement, "personnel:{$people['driver']->id}", 'gcash', now()->toDateString(), (string) Str::uuid(), 'DEMO-SIMULATED-GCASH-001', notes: 'Simulated payout (demonstration)');
        $this->payouts->record($this->owner, $statement, "user:{$leader->id}", 'cash', now()->toDateString(), (string) Str::uuid(), 'DEMO-SIMULATED-CASH-001', notes: 'Simulated payout (demonstration)');

        return ['unit' => $unit->name, 'booking' => $booking->booking_code, 'statement' => $statement->reference_code, 'statement_id' => $statement->id];
    }

    private function missingRider(): array
    {
        [$unit, $leader] = $this->unit('DEMO Unit 2 — Missing Rider');
        $booking = $this->booking($unit, '12050.00', 'Missing Rider Customer');
        $this->fullCrew($booking, $unit, $leader, withRider: false);
        // A Team Leader expense waiting for the Dispatcher, to demonstrate the review flow.
        $this->expenses->submit($leader, $this->expenseData($booking, $unit, 'parking', '80.00', 'Parking at the pickup site (demonstration)'), (string) Str::uuid(), [$this->receipt()]);
        $statement = $this->statements->submitForReview($this->owner,
            $this->statements->generate($this->owner, $this->day->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), $unit->id));

        return ['unit' => $unit->name, 'booking' => $booking->booking_code, 'statement' => $statement->reference_code];
    }

    private function partialPayment(): array
    {
        [$unit, $leader] = $this->unit('DEMO Unit 3 — Partial Payment');
        $booking = $this->booking($unit, '15000.00', 'Partial Payment Customer', [
            'status' => 'waiting_verification', 'completed_at' => null, 'payment_method' => 'gcash', 'cash_received' => null,
        ]);
        $this->fullCrew($booking, $unit, $leader);
        PaymentTransaction::create([
            'provider' => 'paymongo', 'reference' => 'DEMO-PARTIAL-' . Str::upper(Str::random(6)), 'payable_type' => 'booking', 'payable_id' => $booking->id,
            'booking_id' => $booking->id, 'customer_id' => $booking->customer_id, 'method' => 'gcash', 'amount_centavos' => 750000,
            'status' => 'paid', 'livemode' => true, 'paid_at' => $this->day->addHours(2),
        ]);

        return ['unit' => $unit->name, 'booking' => $booking->booking_code];
    }

    private function twoUnit(): array
    {
        [$unit, $leader] = $this->unit('DEMO Unit 4 — Two-unit job (main)');
        [$helper] = $this->unit('DEMO Unit 5 — Two-unit job (helper)');
        $booking = $this->booking($unit, '15000.00', 'Two-unit Customer');
        $this->fullCrew($booking, $unit, $leader);
        $this->approvedExpense($booking, $unit, $leader, 'assistance_other_unit', '500.00', "Assistance from {$helper->name} (demonstration)", 'company_direct');
        $this->review->create($this->owner, [
            'kind' => 'decision',
            'title' => "Two-unit booking {$booking->booking_code}: proposed 50:50 split (review only)",
            'question' => "{$helper->name} helped {$unit->name} on booking {$booking->booking_code} (₱15,000.00). Proposed for discussion: ₱7,500.00 to each unit. Should this split be used, and should it become a standard rule?",
            'current_behaviour' => 'The allocation stays "pending review". The 50:50 split is NOT applied.',
            'blocks' => 'Commission for this booking for both units.',
        ]);

        return ['unit' => $unit->name, 'helper_unit' => $helper->name, 'booking' => $booking->booking_code];
    }

    private function personalAdvance(): array
    {
        [$unit, $leader] = $this->unit('DEMO Unit 6 — Personal Advance');
        $booking = $this->booking($unit, '10000.00', 'Personal Advance Customer');
        $this->fullCrew($booking, $unit, $leader);
        $this->approvedExpense($booking, $unit, $leader, 'repairs', '2000.00', 'Tire repair paid by the Team Leader personally (demonstration)', 'personal_advance');
        $this->review->create($this->owner, [
            'kind' => 'decision',
            'title' => "Personal advance of ₱2,000.00 on booking {$booking->booking_code}",
            'question' => 'The Team Leader paid ₱2,000.00 for a repair personally. How and when is it reimbursed, and should it reduce the commission base?',
            'current_behaviour' => 'Recorded as a personal advance and flagged. No reimbursement or debt is created; approval is blocked.',
            'blocks' => "Commission approval for {$unit->name} in that week.",
        ]);

        return ['unit' => $unit->name, 'booking' => $booking->booking_code];
    }

    private function threeVehicles(): array
    {
        [$a, $leaderA] = $this->unit('DEMO Unit 7 — Three vehicles (2)');
        [$b, $leaderB] = $this->unit('DEMO Unit 8 — Three vehicles (1)');
        $customer = $this->customer('Three-vehicle Customer');
        $code = 'DEMO-GRP-' . Str::upper(Str::random(4));
        $member = fn (Unit $u, string $total) => $this->booking($u, $total, null, ['customer_id' => $customer->id, 'group_code' => $code,
            'pickup_address' => 'Demo group pickup, Marilao', 'dropoff_address' => 'Demo group drop-off, Quezon City', 'cash_received' => '15000.00']);
        $m1 = $member($a, '6000.00');
        $m2 = $member($a, '5000.00');
        $m3 = $member($b, '4000.00');
        $q = Quotation::create(['quotation_number' => 'DEMO-QT-' . Str::upper(Str::random(5)), 'source_booking_id' => $m1->id, 'customer_id' => $customer->id, 'truck_type_id' => $m1->truck_type_id,
            'pickup_address' => 'Demo group pickup, Marilao', 'dropoff_address' => 'Demo group drop-off, Quezon City', 'distance_km' => 30, 'estimated_price' => '15000', 'additional_fee' => '0', 'discount' => '0',
            'service_type' => 'book_now', 'status' => 'accepted', 'extra_vehicles' => [
                ['booking_id' => $m2->id, 'truck_type_id' => $m2->truck_type_id, 'final_total' => 5000.0],
                ['booking_id' => $m3->id, 'truck_type_id' => $m3->truck_type_id, 'final_total' => 4000.0],
            ]]);
        foreach ([$m1, $m2, $m3] as $m) {
            $m->update(['quotation_id' => $q->id]);
        }
        $this->fullCrew($m1, $a, $leaderA);
        $this->fullCrew($m1, $b, $leaderB);

        return ['units' => [$a->name, $b->name], 'booking' => $m1->booking_code, 'group_code' => $code];
    }

    // ------------------------------------------------------------------ building blocks

    private function standInPolicy(): CommissionPolicy
    {
        $existing = CommissionPolicy::where('name', self::POLICY_NAME)->first();
        if ($existing) {
            return $existing;
        }
        $draft = $this->policies->createDraft($this->owner, self::POLICY_NAME, ['company' => '60', 'driver' => '12', 'team_leader' => '10', 'rider' => '10', 'crew_mate' => '8'],
            'DEMONSTRATION DATABASE ONLY. Simulates a future client confirmation so statements and simulated payouts can be shown. The real policy v1 remains pending client confirmation.');
        $this->policies->confirmInterpretation($this->owner, $draft, self::STAND_IN, self::STAND_IN, ['rider_role' => 'confirmed']);

        return $this->policies->activate($this->owner, $draft->fresh(), $this->day->startOfWeek(CarbonImmutable::MONDAY)->toDateString());
    }

    private function user(int $role, string $email, string $first, string $last): User
    {
        $user = User::where('email', $email)->first() ?? new User();
        $user->forceFill([
            'name' => "{$first} {$last}", 'first_name' => $first, 'last_name' => $last, 'email' => $email,
            'password' => Hash::make($this->password), 'role_id' => $role, 'status' => 'active', 'must_change_password' => false,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    /** @return array{0: Unit, 1: User} */
    private function unit(string $name): array
    {
        $this->seq++;
        $leader = $this->user(3, "demo.tl{$this->seq}@towmate.test", 'Demo TL', (string) $this->seq);
        $type = TruckType::firstOrCreate(['name' => 'DEMO Flatbed'], ['base_rate' => 1500, 'per_km_rate' => 60]);
        $unit = Unit::create(['name' => $name, 'plate_number' => 'DEMO-' . str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT), 'truck_type_id' => $type->id,
            'status' => 'available', 'team_leader_id' => $leader->id]);

        return [$unit, $leader];
    }

    private function customer(string $name): Customer
    {
        return Customer::create(['full_name' => $name, 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'demo-' . Str::lower(Str::random(8)) . '@example.test']);
    }

    private function booking(Unit $unit, string $total, ?string $customer, array $overrides = []): Booking
    {
        $at = $this->day;

        return Booking::create(array_merge([
            'customer_id' => $customer ? $this->customer($customer)->id : null,
            'truck_type_id' => $unit->truck_type_id, 'assigned_unit_id' => $unit->id,
            'pickup_address' => 'Demo pickup ' . Str::random(4) . ', Bulacan', 'dropoff_address' => 'Demo drop-off, Quezon City', 'distance_km' => 30,
            'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => $total, 'final_total' => $total,
            'completion_requested_at' => $at, 'payment_submitted_at' => $at, 'payment_method' => 'cash', 'cash_received' => $total,
            'status' => 'completed', 'completed_at' => $at->addHour(),
        ], $overrides))->fresh();
    }

    private function fullCrew(Booking $booking, Unit $unit, User $leader, bool $withRider = true): array
    {
        $driver = $this->person('Dario', 'Driver ' . $this->seq, 'driver', $unit);
        $rider = $this->person('Rico', 'Rider ' . $this->seq, 'driver', $unit);
        $mate = $this->person('Carlo', 'Crew ' . $this->seq, 'crew', $unit);
        $this->crew->record($this->owner, $booking, ['unit_id' => $unit->id, 'participant' => "user:{$leader->id}", 'commission_role' => 'team_leader']);
        $this->crew->record($this->owner, $booking, ['unit_id' => $unit->id, 'participant' => "personnel:{$driver->id}", 'commission_role' => 'driver']);
        if ($withRider) {
            $this->crew->record($this->owner, $booking, ['unit_id' => $unit->id, 'participant' => "personnel:{$rider->id}", 'commission_role' => 'rider']);
        }
        $this->crew->record($this->owner, $booking, ['unit_id' => $unit->id, 'participant' => "personnel:{$mate->id}", 'commission_role' => 'crew_mate']);

        return ['driver' => $driver, 'rider' => $rider, 'crew' => $mate];
    }

    private function person(string $first, string $last, string $role, Unit $unit): Personnel
    {
        return Personnel::create(['first_name' => $first, 'last_name' => $last, 'role' => $role, 'home_unit_id' => $unit->id, 'personnel_status' => 'active']);
    }

    /** Team Leader submits → Dispatcher verifies → Owner approves (the confirmed review flow). */
    private function approvedExpense(Booking $booking, Unit $unit, User $leader, string $category, string $amount, string $description, string $source = 'unit_fund'): void
    {
        $expense = $this->expenses->submit($leader, $this->expenseData($booking, $unit, $category, $amount, $description, $source), (string) Str::uuid(), [$this->receipt()]);
        $expense = $this->expenses->verify($this->dispatcher, $expense, 'Receipt checked (demonstration)');
        $this->expenses->approve($this->owner, $expense);
    }

    private function expenseData(Booking $booking, Unit $unit, string $category, string $amount, string $description, string $source = 'unit_fund'): array
    {
        return ['unit_id' => $unit->id, 'expense_scope' => 'job', 'booking_id' => $booking->booking_code, 'expense_date' => $this->day->toDateString(),
            'category' => $category, 'description' => $description, 'amount' => $amount, 'fundings' => [$source => $amount]];
    }

    /** A small, valid PNG "receipt" so the evidence rules are exercised exactly as in real use. */
    private function receipt(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        // A unique comment chunk keeps every receipt's hash distinct.
        $png = substr($png, 0, -12) . pack('N', 9) . 'tEXt' . 'demo' . "\0" . Str::random(4) . pack('N', crc32('tEXtdemo' . "\0")) . substr($png, -12);

        return UploadedFile::fake()->createWithContent('demo-receipt-' . Str::random(6) . '.png', $png);
    }
}
