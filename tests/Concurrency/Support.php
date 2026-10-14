<?php

/*
 * Shared helpers for the concurrency tests (see HomeAssignmentConcurrencyTest
 * for how sessions are run and synchronised).
 */

use App\Exceptions\HomeUnitConflictException;
use App\Models\AuditLog;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use App\Services\PersonnelService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

const CC_PAUSE_KEY = 424242;

/** Test-only trigger: the worker named "cc_pause" waits on its first audit_logs insert until the holder releases CC_PAUSE_KEY. */
function ccInstallPauseTrigger(): void
{
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION cc_pause() RETURNS trigger AS $$
        BEGIN
            IF current_setting('application_name') = 'cc_pause' THEN
                PERFORM pg_advisory_xact_lock(424242);
            END IF;
            RETURN NEW;
        END $$ LANGUAGE plpgsql;
        DROP TRIGGER IF EXISTS cc_pause ON audit_logs;
        CREATE TRIGGER cc_pause BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION cc_pause();
    SQL);
}

function ccDropPauseTrigger(): void
{
    DB::unprepared('DROP TRIGGER IF EXISTS cc_pause ON audit_logs; DROP FUNCTION IF EXISTS cc_pause();');
}

function ccRole(int $id, string $name): void
{
    if (! Role::find($id)) {
        $role = new Role(['name' => $name]);
        $role->id = $id;
        $role->save();
    }
}

function ccOwner(): User
{
    ccRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function ccDispatcher(): User
{
    ccRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function ccAccount(int $roleId, string $first, string $last, array $attrs = []): User
{
    ccRole($roleId, $roleId === 3 ? 'Team Leader' : 'Driver');

    return User::factory()->create(array_merge([
        'role_id' => $roleId, 'status' => 'active', 'must_change_password' => false,
        'first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'name' => "$first $last",
    ], $attrs));
}

function ccRecord(string $first, string $last, array $attrs = []): Personnel
{
    return Personnel::create(array_merge(['first_name' => $first, 'last_name' => $last, 'role' => 'crew', 'personnel_status' => 'active'], $attrs));
}

function ccUnit(string $name): Unit
{
    $type = TruckType::firstOrCreate(['name' => 'CC Light'], ['base_rate' => 1500, 'per_km_rate' => 60]);

    return Unit::create(['name' => $name, 'plate_number' => 'CC' . random_int(1000, 9999) . strtoupper(substr(md5($name), 0, 3)), 'truck_type_id' => $type->id, 'status' => 'available']);
}

/**
 * Starts one request in its own process. $pgOptions become libpq PGOPTIONS
 * for that session only, e.g. "-c lock_timeout=500" or a long
 * deadlock_timeout so the *other* session is the one Postgres aborts.
 */
function ccStart(User $actor, string $method, string $uri, array $data = [], bool $json = true, string $appName = 'cc_worker', string $pgOptions = ''): Process
{
    return ccSpawn(['actor_id' => $actor->id, 'method' => $method, 'uri' => $uri, 'data' => $data, 'json' => $json, 'app_name' => $appName], $pgOptions);
}

/** Starts an artisan command in its own process (result: exit, output). */
function ccStartArtisan(string $command, array $options = [], string $appName = 'cc_worker', string $pgOptions = ''): Process
{
    return ccSpawn(['artisan' => $command, 'data' => $options, 'app_name' => $appName], $pgOptions);
}

function ccSpawn(array $job, string $pgOptions = ''): Process
{
    $db = config('database.connections.pgsql');
    $job = base64_encode(json_encode($job));

    $process = new Process([PHP_BINARY, __DIR__ . '/worker.php', $job], base_path(), [
        'APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'BCRYPT_ROUNDS' => '4',
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'],
        'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => (string) $db['password'],
        'PGOPTIONS' => $pgOptions,
    ], null, 60);
    $process->start();

    return $process;
}

/** Waits for a worker and returns its result. */
function ccResult(Process $process): array
{
    $process->wait();
    $output = $process->getOutput();
    $marker = strrpos($output, '@@RESULT@@');

    if ($marker === false) {
        throw new RuntimeException("Worker produced no result.\nOUT: {$output}\nERR: " . $process->getErrorOutput());
    }

    return json_decode(trim(substr($output, $marker + 10)), true);
}

/** A separate connection the test uses to hold locks. */
function ccHolder(): PDO
{
    $db = config('database.connections.pgsql');
    $pdo = new PDO("pgsql:host={$db['host']};port={$db['port']};dbname={$db['database']}", $db['username'], $db['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET application_name = 'cc_holder'");

    return $pdo;
}

/** Number of worker sessions currently waiting for a lock. */
function ccWaiting(): int
{
    return (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND application_name LIKE 'cc_%' AND application_name <> 'cc_holder'");
}

/** Waits until $count workers are blocked on locks; false on timeout. */
function ccAwaitWaiting(int $count, float $seconds = 20): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if (ccWaiting() >= $count) {
            return true;
        }
        usleep(50_000);
    }

    return false;
}

/** The Home Team batch request for one unit, built from its current state. */
function ccBatch(Unit $unit, array $crew = [], array $teamLeaders = [], array $drivers = []): array
{
    $service = app(PersonnelService::class);
    $people = collect($service->homeTeamPeople())->keyBy('key');
    $current = $people->where('home_unit_id', $unit->id)->keys();
    $wanted = collect([...$teamLeaders, ...$drivers, ...$crew]);
    $expected = $wanted->diff($current)->concat($current->diff($wanted))
        ->mapWithKeys(fn ($key) => [$key => $people[$key]['home_unit_id']]);

    return [
        'token' => $service->homeTeamToken($unit->id),
        'team_leader' => array_values($teamLeaders),
        'driver' => array_values($drivers),
        'crew' => array_values($crew),
        'expected' => (object) $expected->all(),
    ];
}

function ccHome(Unit $unit): array
{
    return User::where('home_unit_id', $unit->id)->pluck('id')->map(fn ($id) => "account-$id")
        ->concat(Personnel::where('home_unit_id', $unit->id)->pluck('id')->map(fn ($id) => "record-$id"))
        ->sort()->values()->all();
}

function ccNoServerError(array $result): void
{
    expect($result['status'])->toBeLessThan(500, json_encode($result));
    expect($result['exception'])->toBeNull();
    expect(json_encode($result))->not->toContain('SQLSTATE');
}

/** Puts a personnel record in a unit's slot, as a dispatcher placement would. */
function ccSeat(Unit $unit, string $slot, Personnel $person): void
{
    $unit->update([Unit::SLOT_COLUMNS[$slot] => $person->full_name, Unit::SLOT_PERSONNEL_COLUMNS[$slot] => $person->id]);
}

/** An open borrow record, matching what the dispatcher methods create. */
function ccLoan(Unit $from, string $fromSlot, Unit $to, string $toSlot, Personnel|User $person): UnitCrewLoan
{
    return UnitCrewLoan::create([
        'from_unit_id' => $from->id, 'to_unit_id' => $to->id, 'from_slot' => $fromSlot, 'to_slot' => $toSlot,
        'person_name' => $person->full_name,
        'personnel_id' => $person instanceof Personnel ? $person->id : null,
        'person_user_id' => $person instanceof User ? $person->id : null,
        'borrowed_at' => now(),
    ]);
}

/**
 * No team, driver, crew or borrow-record corruption: each person sits in at
 * most one slot, each leader leads at most one unit, each person has at most
 * one open loan, and every open loan matches the slot it claims.
 */
function ccAssertTeamIntegrity(): void
{
    $placed = [];
    foreach (Unit::orderBy('id')->get() as $unit) {
        foreach (Unit::SLOT_PERSONNEL_COLUMNS as $column) {
            if ($unit->{$column}) {
                expect($placed)->not->toHaveKey((int) $unit->{$column});
                $placed[(int) $unit->{$column}] = true;
            }
        }
    }

    expect(Unit::whereNotNull('team_leader_id')->select('team_leader_id')->groupBy('team_leader_id')->havingRaw('count(*) > 1')->exists())->toBeFalse();

    $open = UnitCrewLoan::whereNull('returned_at')->get();
    expect($open->whereNotNull('personnel_id')->groupBy('personnel_id')->filter(fn ($loans) => $loans->count() > 1)->all())->toBe([]);
    expect($open->whereNotNull('person_user_id')->groupBy('person_user_id')->filter(fn ($loans) => $loans->count() > 1)->all())->toBe([]);

    foreach ($open as $loan) {
        $to = Unit::find($loan->to_unit_id);
        $expected = $loan->to_slot === 'team_leader' ? $to->team_leader_id : $to->{Unit::SLOT_PERSONNEL_COLUMNS[$loan->to_slot]};
        expect((int) $expected)->toBe((int) ($loan->person_user_id ?? $loan->personnel_id), "open loan {$loan->id} does not match {$to->name}");
    }
}

/** Units, loans and audit rows as one comparable snapshot. */
function ccSnapshot(): array
{
    return [
        'units' => Unit::orderBy('id')->get()->map(fn (Unit $unit) => collect($unit->getAttributes())->except(['updated_at'])->all())->all(),
        'loans' => UnitCrewLoan::orderBy('id')->get()->map(fn ($loan) => collect($loan->getAttributes())->except(['updated_at'])->all())->all(),
        'audits' => AuditLog::count(),
    ];
}

/* ---------- Scheduled-booking capacity fixtures ---------- */

/** A customer user (role 5) with its Customer record. */
function ccCustomer(string $name = 'Cap Customer'): array
{
    ccRole(5, 'Customer');

    $user = User::factory()->create(['role_id' => 5, 'status' => 'active', 'must_change_password' => false, 'name' => $name]);
    $customer = \App\Models\Customer::create([
        'user_id' => $user->id, 'full_name' => $name, 'phone' => '+639' . random_int(100000000, 999999999),
        'email' => strtolower(str_replace(' ', '.', $name)) . random_int(100, 999) . '@example.test',
    ]);

    return [$user, $customer];
}

function ccScheduledBooking(\App\Models\Customer $customer, array $attrs = []): \App\Models\Booking
{
    $type = TruckType::firstOrCreate(['name' => 'CC Light'], ['base_rate' => 1500, 'per_km_rate' => 60]);
    $booking = \App\Models\Booking::create(array_merge([
        'customer_id' => $customer->id, 'truck_type_id' => $type->id,
        'pickup_address' => 'Pickup St', 'dropoff_address' => 'Dropoff St', 'distance_km' => 5,
        'status' => 'scheduled', 'service_type' => 'schedule', 'scheduled_date' => '2030-03-10', 'scheduled_time' => '09:00',
        'scheduled_expires_at' => now()->addDays(7), 'final_total' => 2000,
    ], $attrs));
    $booking->update(['booking_code' => 'TM-' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT)]);

    return $booking->fresh();
}

/** A sent quotation for a scheduled booking, optionally covering existing sibling bookings (one slot each). */
function ccSentQuotation(\App\Models\Booking $primary, array $siblings = []): \App\Models\Quotation
{
    $service = app(\App\Services\QuotationService::class);
    $quotation = $service->createQuotation([
        'source_booking_id' => $primary->id, 'customer_id' => $primary->customer_id, 'truck_type_id' => $primary->truck_type_id,
        'pickup_address' => $primary->pickup_address, 'dropoff_address' => $primary->dropoff_address, 'distance_km' => 5,
        'estimated_price' => 2000, 'service_type' => 'schedule',
        'scheduled_date' => $primary->scheduled_date->toDateString(), 'scheduled_time' => $primary->scheduled_time,
        'extra_vehicles' => array_map(fn ($sibling) => [
            'booking_id' => $sibling->id, 'service_type' => 'schedule', 'truck_type_id' => $sibling->truck_type_id,
            'final_total' => 1800, 'estimated_price' => 1800,
        ], $siblings) ?: null,
    ]);
    $service->sendQuotation($quotation);

    return $quotation->fresh();
}

function ccSlots(string $date): ?int
{
    $used = DB::table('booking_capacity')->where('booking_date', $date)->value('slots_used');

    return $used === null ? null : (int) $used;
}
