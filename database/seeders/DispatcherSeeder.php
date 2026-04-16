<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DispatcherSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $email = strtolower((string) env('DISPATCHER_EMAIL', 'dispatcher@gmail.com'));
        $password = (string) env('DISPATCHER_PASSWORD', 'dispatcher123');
        $name = (string) env('DISPATCHER_NAME', 'Test Dispatcher');

        $values = [
            'name' => $name,
            'password' => Hash::make($password),
        ];

        if (Schema::hasColumn('users', 'role_id')) {
            $values['role_id'] = 2;
        }

        if (Schema::hasColumn('users', 'status')) {
            $values['status'] = 'active';
        }

        User::updateOrCreate(['email' => $email], $values);
    }
}
