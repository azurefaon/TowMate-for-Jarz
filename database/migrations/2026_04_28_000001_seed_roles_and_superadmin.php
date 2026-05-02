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

        // Seed roles
        if (Schema::hasTable('roles')) {
            $now = now();
            DB::table('roles')->insertOrIgnore([
                ['id' => 1, 'name' => 'Super Admin', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'name' => 'Admin',       'created_at' => $now, 'updated_at' => $now],
                ['id' => 3, 'name' => 'Team Leader', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 4, 'name' => 'Driver',      'created_at' => $now, 'updated_at' => $now],
            ]);

            // PostgreSQL sequences don't advance on explicit-ID inserts
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
            }
        }

        // Seed superadmin
        $email    = strtolower((string) env('SUPERADMIN_EMAIL',    'superadmin@gmail.com'));
        $password = (string) env('SUPERADMIN_PASSWORD', 'admin123456');
        $name     = (string) env('SUPERADMIN_NAME',     'System SuperAdmin');
        $now      = now();

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
            // Only update password + role so we don't clobber other fields
            DB::table('users')->where('email', $email)->update($values);
        } else {
            $insert = array_merge($values, [
                'email'      => $email,
                'created_at' => $now,
            ]);

            if (Schema::hasColumn('users', 'user_code')) {
                $insert['user_code'] = strtoupper('SA-' . Str::random(4));
                // $insert['user_code'] = strtoupper('SA-' . Str::random(6));
            }

            DB::table('users')->insert($insert);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op — don't delete accounts on rollback
    }
};
