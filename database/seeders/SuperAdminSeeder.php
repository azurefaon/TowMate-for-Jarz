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

        $email    = strtolower(trim((string) env('SUPERADMIN_EMAIL',    'superadmin@gmail.com')));
        $password = (string) env('SUPERADMIN_PASSWORD', 'admin123456');
        $name     = (string) env('SUPERADMIN_NAME',     'System SuperAdmin');
        $now      = now();

        // ── Seed roles ────────────────────────────────────────────────────
        if (Schema::hasTable('roles')) {
            DB::table('roles')->insertOrIgnore([
                ['id' => 1, 'name' => 'Super Admin', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'name' => 'Admin',       'created_at' => $now, 'updated_at' => $now],
                ['id' => 3, 'name' => 'Team Leader', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 4, 'name' => 'Driver',      'created_at' => $now, 'updated_at' => $now],
            ]);

            // Advance PostgreSQL sequence after explicit-ID inserts.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
            }
        }

        // ── Upsert Super Admin ────────────────────────────────────────────
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
            // Only update password + role so we don't clobber other fields.
            DB::table('users')->where('email', $email)->update($values);
        } else {
            $insert = array_merge($values, [
                'email'      => $email,
                'created_at' => $now,
            ]);

            if (Schema::hasColumn('users', 'user_code')) {
                $insert['user_code'] = strtoupper('SA-' . Str::random(6));
            }

            DB::table('users')->insert($insert);
        }
    }
}
