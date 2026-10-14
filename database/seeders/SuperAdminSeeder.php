<?php

namespace Database\Seeders;

use App\Services\InitialProvisioningService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The Owner account.
 *
 * Staging and production (any environment other than local and testing):
 * insert-only. If an Owner account exists, whatever its email, password or
 * status, nothing is touched. If none exists it is created only when
 * SUPERADMIN_PASSWORD is set; a password is never generated for a live
 * system, because there would be no way to tell the owner what it is.
 *
 * Local and testing keep the original behaviour (create or reset the account
 * from SUPERADMIN_*), which the test suite relies on.
 *
 * No password is ever printed. See docs/DEPLOYMENT.md.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $provisioning = app(InitialProvisioningService::class);

        if (! app()->environment('local', 'testing')) {
            $this->provisionLive($provisioning);

            return;
        }

        $this->createOrResetForDevelopment($provisioning);
    }

    protected function provisionLive(InitialProvisioningService $provisioning): void
    {
        if ($provisioning->ownerExists()) {
            $this->command?->info('Owner account already exists; left unchanged.');

            return;
        }

        $password = (string) env('SUPERADMIN_PASSWORD');

        if ($password === '') {
            $this->command?->warn('No Owner account exists and SUPERADMIN_PASSWORD is not set, so none was created. Set it and run: php artisan towmate:provision');

            return;
        }

        if ($problem = $provisioning->passwordProblem($password)) {
            $this->command?->error($problem);

            return;
        }

        $created = $provisioning->provisionOwner($provisioning->configuredOwnerEmail(), $provisioning->configuredOwnerName(), $password);
        $this->command?->info($created ? 'Owner account created.' : 'Owner account was not created (the email is already in use).');
    }

    protected function createOrResetForDevelopment(InitialProvisioningService $provisioning): void
    {
        $email = $provisioning->configuredOwnerEmail();
        $name = $provisioning->configuredOwnerName();
        $now = now();

        $envPassword = env('SUPERADMIN_PASSWORD');
        $password = blank($envPassword) ? Str::random(20) : (string) $envPassword;

        if (blank($envPassword)) {
            $this->command?->warn('SUPERADMIN_PASSWORD was not set, so a random password was set that is not shown. Set SUPERADMIN_PASSWORD and seed again to choose one.');
        }

        if (Schema::hasTable('roles')) {
            DB::table('roles')->insertOrIgnore([
                ['id' => 1, 'name' => 'Owner', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'name' => 'Admin', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 3, 'name' => 'Team Leader', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 4, 'name' => 'Driver', 'created_at' => $now, 'updated_at' => $now],
            ]);

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
            }
        }

        $values = [
            'name' => $name,
            'password' => Hash::make($password),
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('users', 'role_id') && Schema::hasTable('roles')) {
            $values['role_id'] = 1;
        }

        if (Schema::hasColumn('users', 'status')) {
            $values['status'] = 'active';
        }

        if (DB::table('users')->where('email', $email)->exists()) {
            DB::table('users')->where('email', $email)->update($values);

            return;
        }

        $insert = array_merge($values, ['email' => $email, 'created_at' => $now]);

        if (Schema::hasColumn('users', 'user_code')) {
            $insert['user_code'] = $this->nextUserCode();
        }

        DB::table('users')->insert($insert);
    }

    protected function nextUserCode(): string
    {
        $highest = DB::table('users')
            ->whereNotNull('user_code')
            ->pluck('user_code')
            ->map(fn ($value) => (int) preg_replace('/\D+/', '', (string) $value))
            ->max() ?? 0;

        return str_pad((string) ($highest + 1), 7, '0', STR_PAD_LEFT);
    }
}
