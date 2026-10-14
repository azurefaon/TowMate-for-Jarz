<?php

/*
 * Deployment safety: a deploy, or a second provisioning run, must never
 * overwrite owner-managed truck types or the Owner account, and nothing here
 * may print a password. Live behaviour is simulated by setting the app
 * environment to production (the suite itself runs as "testing").
 */

use App\Models\TruckType;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const PS_OWNER_PASSWORD = 'First-Owner-Passw0rd!';

function psAsProduction(): void
{
    app()['env'] = 'production';
}

function psEnv(?string $password, ?string $email = null): void
{
    foreach (['SUPERADMIN_PASSWORD' => $password, 'SUPERADMIN_EMAIL' => $email, 'SUPERADMIN_NAME' => null] as $key => $value) {
        $value === null ? putenv($key) : putenv("{$key}={$value}");
    }
}

/** A new environment: migrated, with the owner the test migration made removed. */
function psFreshInstall(): void
{
    psAsProduction();
    DB::table('users')->delete();
    DB::table('truck_types')->delete();
}

function psProvision(array $options = []): array
{
    $exit = Artisan::call('towmate:provision', $options);

    return [$exit, Artisan::output()];
}

function psSeed(string $class): void
{
    Artisan::call('db:seed', ['--class' => $class, '--force' => true]);
}

/** Every user and truck type row, exactly as stored. */
function psSnapshot(): array
{
    return [
        'users' => DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'truck_types' => DB::table('truck_types')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'vehicle_types' => DB::table('vehicle_types')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

afterEach(fn () => psEnv(null));

/* ---------- Release command ---------- */

it('release command runs migrations only, with no seeding or provisioning', function () {
    $toml = file_get_contents(base_path('railway.toml'));

    preg_match('/^releaseCommand\s*=\s*"([^"]*)"/m', $toml, $match);

    expect($match[1] ?? null)->toBe('php artisan migrate --force');
    expect($match[1])->not->toContain('seed')->not->toContain('provision');
});

it('no other deploy configuration seeds or provisions', function () {
    foreach (['railway.toml', 'composer.json', '.github/workflows/tests.yml'] as $file) {
        $contents = preg_replace('/^\s*#.*$/m', '', file_get_contents(base_path($file)));

        expect($contents)->not->toContain('db:seed');
        expect($contents)->not->toContain('towmate:provision');
    }
});

it('the first-install migration never prints a password', function () {
    $source = file_get_contents(database_path('migrations/2026_04_28_000001_seed_roles_and_superadmin.php'));

    expect($source)->not->toContain('one-time password')->not->toContain('{$password}');
});

/* ---------- Fresh install ---------- */

it('provisions the owner and truck types on a fresh install, without printing the password', function () {
    psFreshInstall();
    psEnv(PS_OWNER_PASSWORD, 'Owner@Example.test');

    [$exit, $output] = psProvision();

    expect($exit)->toBe(0);
    expect($output)->not->toContain(PS_OWNER_PASSWORD)->toContain('Owner account: created for owner@example.test');

    $owner = User::where('email', 'owner@example.test')->first();
    expect($owner)->not->toBeNull();
    expect((int) $owner->role_id)->toBe(1)->and($owner->status)->toBe('active');
    expect(Hash::check(PS_OWNER_PASSWORD, $owner->password))->toBeTrue();
    expect(User::count())->toBe(1);

    $types = TruckType::orderBy('id')->get();
    expect($types->pluck('name')->all())->toBe(['Light Duty', 'Medium Duty', 'Heavy Duty']);
    expect($types->map(fn ($t) => [(float) $t->base_rate, (float) $t->per_km_rate, (float) $t->max_tonnage, $t->class, $t->status])->all())->toBe([
        [1500.0, 300.0, 4.5, 'light', 'active'],
        [2500.0, 300.0, 7.5, 'medium', 'active'],
        [4500.0, 300.0, 999.0, 'heavy', 'active'],
    ]);
});

it('never generates an owner password: without one the owner is not created and the command fails', function () {
    psFreshInstall();
    psEnv(null);

    [$exit, $output] = psProvision();

    expect($exit)->toBe(1);
    expect($output)->toContain('SUPERADMIN_PASSWORD');
    expect(User::count())->toBe(0);

    // Truck types are still provisioned; only the owner step failed.
    expect(TruckType::count())->toBe(3);
});

it('refuses a short owner password', function () {
    psFreshInstall();
    psEnv('short');

    [$exit] = psProvision();

    expect($exit)->toBe(1)->and(User::count())->toBe(0);
});

it('falls back to the default owner email when SUPERADMIN_EMAIL is blank', function () {
    psFreshInstall();
    psEnv(PS_OWNER_PASSWORD, '');

    psProvision();

    expect(User::where('email', 'superadmin@gmail.com')->count())->toBe(1);
});

it('the SuperAdminSeeder creates the owner on a live install only from an explicit password', function () {
    psFreshInstall();
    psEnv(null);
    psSeed(\Database\Seeders\SuperAdminSeeder::class);
    expect(User::count())->toBe(0);

    psEnv(PS_OWNER_PASSWORD);
    psSeed(\Database\Seeders\SuperAdminSeeder::class);
    expect(User::count())->toBe(1);
    expect(Hash::check(PS_OWNER_PASSWORD, User::first()->password))->toBeTrue();
});

/* ---------- Second run and deploys change nothing ---------- */

it('a second provisioning run and every seeder run change no existing record', function () {
    psFreshInstall();
    psEnv(PS_OWNER_PASSWORD);
    psProvision(['--vehicle-types' => true]);
    $before = psSnapshot();

    psProvision(['--vehicle-types' => true]);
    foreach ([\Database\Seeders\SuperAdminSeeder::class, \Database\Seeders\TruckTypeSeeder::class, \Database\Seeders\VehicleTypeSeeder::class, DatabaseSeeder::class] as $seeder) {
        psSeed($seeder);
    }

    expect(psSnapshot())->toBe($before);
    expect(VehicleType::count())->toBeGreaterThan(0);
});

it('keeps modified truck-type rates, capacity, class, status and descriptions', function () {
    psFreshInstall();
    psEnv(PS_OWNER_PASSWORD);
    psProvision();

    TruckType::where('name', 'Light Duty')->update([
        'base_rate' => 1999.5, 'per_km_rate' => 412.25, 'max_tonnage' => 5.25, 'class' => 'medium', 'description' => 'Owner wording',
    ]);
    TruckType::where('name', 'Medium Duty')->update(['status' => 'inactive']);
    $before = psSnapshot();

    psProvision();
    psSeed(\Database\Seeders\TruckTypeSeeder::class);
    psSeed(DatabaseSeeder::class);

    expect(psSnapshot())->toBe($before);
    expect(TruckType::where('name', 'Medium Duty')->value('status'))->toBe('inactive');
    expect((float) TruckType::where('name', 'Light Duty')->value('base_rate'))->toBe(1999.5);
});

it('does not recreate a truck type the owner renamed', function () {
    psFreshInstall();
    psEnv(PS_OWNER_PASSWORD);
    psProvision();
    TruckType::where('name', 'Light Duty')->update(['name' => 'Compact Tow']);

    psProvision();
    psSeed(\Database\Seeders\TruckTypeSeeder::class);

    expect(TruckType::count())->toBe(3);
    expect(TruckType::where('name', 'Light Duty')->exists())->toBeFalse();
});

/* ---------- Owner account is never touched ---------- */

it('keeps an owner password that was changed after provisioning', function () {
    psFreshInstall();
    psEnv(PS_OWNER_PASSWORD);
    psProvision();
    User::where('role_id', 1)->update(['password' => Hash::make('Owner-Changed-Passw0rd!')]);

    // The deploy-time variable still holds the old value.
    psProvision();
    psSeed(\Database\Seeders\SuperAdminSeeder::class);
    psSeed(DatabaseSeeder::class);

    $owner = User::where('role_id', 1)->first();
    expect(Hash::check('Owner-Changed-Passw0rd!', $owner->password))->toBeTrue();
    expect(Hash::check(PS_OWNER_PASSWORD, $owner->password))->toBeFalse();
});

it('keeps a disabled owner disabled and never adds a second owner', function () {
    psFreshInstall();
    psEnv(PS_OWNER_PASSWORD, 'owner@example.test');
    psProvision();
    User::where('role_id', 1)->update(['status' => 'inactive', 'email' => 'renamed.owner@example.test', 'name' => 'Renamed Owner']);
    $before = psSnapshot();

    // Same variables as at install time: the default email is free again.
    psProvision();
    psSeed(\Database\Seeders\SuperAdminSeeder::class);

    expect(psSnapshot())->toBe($before);
    expect(User::where('role_id', 1)->count())->toBe(1);
    expect(User::where('role_id', 1)->value('status'))->toBe('inactive');
});

/* ---------- Demo seeders cannot run on a live system ---------- */

it('refuses the demo seeders and the full db:seed outside local and testing', function () {
    psFreshInstall();
    psEnv(PS_OWNER_PASSWORD);
    psProvision();
    $before = psSnapshot();

    foreach (['DispatcherSeeder', 'TeamLeaderSeeder', 'UnitSeeder', 'DatabaseSeeder'] as $seeder) {
        psSeed("Database\\Seeders\\{$seeder}");
    }

    expect(psSnapshot())->toBe($before);
    expect(User::where('email', 'dispatcher@gmail.com')->orWhere('email', 'teamleader@gmail.com')->exists())->toBeFalse();
    expect(DB::table('units')->count())->toBe(0);
});

it('the vehicle type seeder will not touch an existing catalogue on a live system', function () {
    psFreshInstall();
    psEnv(PS_OWNER_PASSWORD);
    psProvision(['--vehicle-types' => true]);
    VehicleType::query()->update(['status' => 'inactive']);
    $before = psSnapshot();

    psSeed(\Database\Seeders\VehicleTypeSeeder::class);

    expect(psSnapshot())->toBe($before);
    expect(VehicleType::where('status', 'active')->count())->toBe(0);
});

it('still lets local and testing seed the development account, as the suite relies on', function () {
    app()['env'] = 'testing';
    psEnv(PS_OWNER_PASSWORD);

    psSeed(\Database\Seeders\SuperAdminSeeder::class);

    $owner = User::where('email', 'superadmin@gmail.com')->first();
    expect(Hash::check(PS_OWNER_PASSWORD, $owner->password))->toBeTrue();
});
