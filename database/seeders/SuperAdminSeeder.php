<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $email = strtolower(trim((string) env('SUPERADMIN_EMAIL', 'superadmin@gmail.com')));
        $name  = (string) env('SUPERADMIN_NAME', 'System SuperAdmin');
        $now   = now();

        $envPassword = env('SUPERADMIN_PASSWORD');
        $generated = blank($envPassword);
        $password = $generated ? Str::random(20) : (string) $envPassword;

        if ($generated) {
            $this->command?->warn("SUPERADMIN_PASSWORD was not set. Generated a one-time password for {$email}: {$password}");
            $this->command?->warn('Log in immediately and change this password, or set SUPERADMIN_PASSWORD before seeding.');
        }

        if (Schema::hasTable('roles')) {
            DB::table('roles')->insertOrIgnore([
                ['id' => 1, 'name' => 'Owner', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'name' => 'Admin',       'created_at' => $now, 'updated_at' => $now],
                ['id' => 3, 'name' => 'Team Leader', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 4, 'name' => 'Driver',      'created_at' => $now, 'updated_at' => $now],
            ]);

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
            }
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
}
