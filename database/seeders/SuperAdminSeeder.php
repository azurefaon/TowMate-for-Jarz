<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $email = strtolower((string) env('SUPERADMIN_EMAIL', 'superadmin@gmail.com'));
        $password = (string) env('SUPERADMIN_PASSWORD', 'admin123456');
        $name = (string) env('SUPERADMIN_NAME', 'System SuperAdmin');

        if (Schema::hasTable('roles')) {
            DB::table('roles')->insertOrIgnore([
                ['id' => 1, 'name' => 'Super Admin'],
                ['id' => 2, 'name' => 'Admin'],
                ['id' => 3, 'name' => 'Team Leader']
            ]);
        }

        $values = [
            'name' => $name,
            'password' => $password,
        ];

        if (Schema::hasColumn('users', 'role_id') && Schema::hasTable('roles')) {
            $values['role_id'] = 1;
        }

        if (Schema::hasColumn('users', 'status')) {
            $values['status'] = 'active';
        }

        User::updateOrCreate(['email' => $email], $values);
    }
}
