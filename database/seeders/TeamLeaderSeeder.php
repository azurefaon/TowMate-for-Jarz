<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class TeamLeaderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $email = strtolower((string) env('TEAMLEADER_EMAIL', 'teamleader@gmail.com'));
        $password = (string) env('TEAMLEADER_PASSWORD', 'teamleader123');
        $name = (string) env('TEAMLEADER_NAME', 'Test Team Leader');

        $values = [
            'name' => $name,
            'password' => Hash::make($password),
        ];

        if (Schema::hasColumn('users', 'role_id')) {
            $values['role_id'] = 3;
        }

        if (Schema::hasColumn('users', 'status')) {
            $values['status'] = 'active';
        }

        User::updateOrCreate(['email' => $email], $values);
    }
}
