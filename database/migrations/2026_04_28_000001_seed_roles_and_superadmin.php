<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasTable('roles')) {
            $now = now();
            DB::table('roles')->insertOrIgnore([
                ['id' => 1, 'name' => 'Super Admin', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'name' => 'Admin',       'created_at' => $now, 'updated_at' => $now],
                ['id' => 3, 'name' => 'Team Leader', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 4, 'name' => 'Driver',      'created_at' => $now, 'updated_at' => $now],
            ]);

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
            }
        }

        // Never prints or logs a password. On a live system (anything but
        // local/testing) the Owner is only created from an explicit
        // SUPERADMIN_PASSWORD, and an existing Owner is never touched; see
        // docs/DEPLOYMENT.md and `php artisan towmate:provision`.
        $email = strtolower(trim((string) env('SUPERADMIN_EMAIL')));
        $email = $email !== '' ? $email : 'superadmin@gmail.com';
        $name  = trim((string) env('SUPERADMIN_NAME')) !== '' ? (string) env('SUPERADMIN_NAME') : 'System SuperAdmin';
        $now   = now();

        $envPassword = env('SUPERADMIN_PASSWORD');

        if (blank($envPassword)) {
            if (! app()->environment('local', 'testing')) {
                fwrite(STDOUT, "\n[TowMate] No Owner account was created because SUPERADMIN_PASSWORD is not set. Set it and run: php artisan towmate:provision\n\n");

                return;
            }

            // Local/testing: a random password that is never shown, so the
            // account exists for development and tests.
            $envPassword = Str::random(20);
        }

        $password = (string) $envPassword;

        if (Schema::hasColumn('users', 'role_id') && DB::table('users')->where('role_id', 1)->exists()) {
            return;
        }

        $values = [
            'name'       => $name,
            'password'   => Hash::make($password),
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('users', 'role_id') && Schema::hasTable('roles')) {
            $values['role_id'] = 1;
        }

        if (Schema::hasColumn('users', 'status')) {
            $values['status'] = 'active';
        }

        $exists = DB::table('users')->where('email', $email)->exists();

        if ($exists) {
            DB::table('users')->where('email', $email)->update($values);
        } else {
            $insert = array_merge($values, [
                'email'      => $email,
                'created_at' => $now,
            ]);

            if (Schema::hasColumn('users', 'user_code')) {
                $insert['user_code'] = $this->nextUserCode();
            }

            DB::table('users')->insert($insert);
        }
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

    public function down(): void {}
};
