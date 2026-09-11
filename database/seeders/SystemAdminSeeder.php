<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SystemAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('roles')) {
            return;
        }

        $now = now();

        DB::table('roles')->insertOrIgnore([
            'id' => 6,
            'name' => 'System Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
        }

        $email = strtolower(trim((string) env('SYSTEM_ADMIN_EMAIL', 'systemadmin@towmate.local')));

        if (DB::table('users')->where('email', $email)->exists()) {
            return;
        }

        $name = (string) env('SYSTEM_ADMIN_NAME', 'System Administrator');

        $envPassword = env('SYSTEM_ADMIN_PASSWORD');
        $needsDefault = blank($envPassword);
        $isLocalLike = app()->environment('local', 'testing');

        if ($needsDefault && $isLocalLike) {
            $password = 'TowMate-SystemAdmin-2026!';
        } elseif ($needsDefault) {
            $password = Str::random(20);
            $this->command?->warn("SYSTEM_ADMIN_PASSWORD was not set. A one-time password was generated for {$email} and is not displayed here for security.");
            $this->command?->warn('Set SYSTEM_ADMIN_PASSWORD and reseed, or use the staff password-recovery flow to set a password via email verification.');
        } else {
            $password = (string) $envPassword;
        }

        $insert = [
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role_id' => 6,
            'status' => 'active',
            'archived_at' => null,
            'must_change_password' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

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
