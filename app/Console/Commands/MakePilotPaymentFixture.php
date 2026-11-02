<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Services\OnlinePayments\Money;
use App\Services\OnlinePayments\PayableResolver;
use App\Services\OnlinePayments\SandboxGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates ONE synthetic customer + ONE synthetic booking awaiting payment (pay at booking) for the
 * PayMongo sandbox pilot. It writes data, so it refuses unless the default database is clearly a
 * throw-away one, and never in production. It touches no existing record.
 */
class MakePilotPaymentFixture extends Command
{
    protected $signature = 'online-payments:make-pilot-fixture
        {--amount=100.00 : Finalized amount in pesos (1.00 to 1000.00)}
        {--email=pilot.customer@gmail.com : Email for the dedicated synthetic test customer}';

    protected $description = 'Create a synthetic test customer and pay-at-booking (awaiting payment) booking for the PayMongo sandbox pilot (isolated databases only)';

    public function handle(SandboxGuard $guard, PayableResolver $resolver): int
    {
        // Checked before any database access.
        if (app()->environment('production') || ! $guard->databaseIsolated()) {
            $this->error('Refusing to write: the database "' . $guard->databaseName() . '" is not a clearly isolated pilot/testing database (its name must end in _testing, _pilot, _sandbox or _isolated).');

            return self::FAILURE;
        }

        $amount = (string) $this->option('amount');
        if (! preg_match('/^\d{1,4}(\.\d{1,2})?$/', $amount) || (float) $amount < 1 || (float) $amount > 1000) {
            $this->error('--amount must be between 1.00 and 1000.00 pesos.');

            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->option('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('--email is not a valid address.');

            return self::FAILURE;
        }

        $booking = DB::transaction(function () use ($email, $amount) {
            $role = Role::find(5) ?: tap(new Role(['name' => 'Customer']), function (Role $role) {
                $role->id = 5;
                $role->save();
            });

            // The factory is the proven way to build a valid user row (all required columns); local/dev only.
            $user = User::where('email', $email)->first() ?: User::factory()->create([
                'name' => 'Pilot Test Customer',
                'email' => $email,
                'password' => Hash::make(Str::random(40)), // nobody logs in as this account
                'role_id' => $role->id,
                'status' => 'active',
                'must_change_password' => false,
            ]);

            $customer = Customer::where('user_id', $user->id)->first() ?: Customer::create([
                'user_id' => $user->id,
                'full_name' => 'Pilot Test Customer',
                'phone' => '0917' . random_int(1000000, 9999999),
                'email' => $email,
            ]);

            $truck = TruckType::firstOrCreate(['name' => 'Pilot Test Truck'], ['base_rate' => 100, 'per_km_rate' => 0]);

            // A pilot fixture is a capstone demo booking by definition: kept out of real revenue.
            return tap(new Booking([
                'customer_id' => $customer->id,
                'truck_type_id' => $truck->id,
                'pickup_address' => 'PILOT TEST pickup (synthetic)',
                'dropoff_address' => 'PILOT TEST drop-off (synthetic)',
                'distance_km' => 1,
                'base_rate' => $amount,
                'per_km_rate' => 0,
                'computed_total' => $amount,
                'vat_exclusive_total' => $amount,
                'vat_amount' => 0,
                'final_total' => $amount,
                'service_type' => 'book_now',
                'status' => Booking::STATUS_AWAITING_PAYMENT,
                'preferred_payment_method' => 'gcash',
            ]), function (Booking $booking) {
                $booking->is_demo = true;
                $booking->save();
            });
        });

        $payable = $resolver->resolve($booking);

        $this->info('Synthetic pilot fixture created in "' . $guard->databaseName() . '".');
        $this->line('Booking code : ' . $booking->booking_code . '  (status ' . $booking->status . ')');
        $this->line('Amount       : PHP ' . $payable->totalPesos . '  = ' . Money::pesosToCentavos($payable->totalPesos) . ' centavos');
        $this->line('Test customer: user id ' . $booking->customer->user_id . ' <' . $email . '>');
        $this->line('Add to the pilot environment file: PAYMONGO_SANDBOX_TEST_USER_IDS=' . $booking->customer->user_id);

        return self::SUCCESS;
    }
}
