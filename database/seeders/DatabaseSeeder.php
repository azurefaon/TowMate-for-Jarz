<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Database\Seeders\Concerns\ProtectsLiveData;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use ProtectsLiveData;
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if ($this->refuseOutsideLocal('Use: php artisan towmate:provision')) {
            return;
        }

        // User::factory(10)->create();
        $this->call([
            SuperAdminSeeder::class,
            SystemAdminSeeder::class,
            DispatcherSeeder::class,
            TeamLeaderSeeder::class,
            TruckTypeSeeder::class,
            VehicleTypeSeeder::class,
            UnitSeeder::class,
        ]);
    }
}
