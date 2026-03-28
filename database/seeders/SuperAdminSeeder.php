<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {

        \Illuminate\Support\Facades\DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'name' => 'Super Admin'],
            ['id' => 2, 'name' => 'Admin'],
            ['id' => 3, 'name' => 'Team Leader'],
            ['id' => 4, 'name' => 'Driver'],
            ['id' => 5, 'name' => 'Customer'],
        ]);

        \App\Models\User::updateOrCreate(
            ['email' => 'superadmin@towmate.test'],
            [
                'name' => 'System SuperAdmin',
                'password' => \Illuminate\Support\Facades\Hash::make('admin123456'),
                'role_id' => 1,
                'status' => 'active'
            ]
        );
    }
}
