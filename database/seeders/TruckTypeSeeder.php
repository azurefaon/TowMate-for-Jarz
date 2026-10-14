<?php

namespace Database\Seeders;

use App\Services\InitialProvisioningService;
use Illuminate\Database\Seeder;

/**
 * Starting truck types for a new install. Insert-only and only on an empty
 * truck_types table: it never changes a rate, tonnage, class, description
 * or status the owner has set, so it is safe to run again.
 */
class TruckTypeSeeder extends Seeder
{
    public function run(): void
    {
        $created = app(InitialProvisioningService::class)->provisionTruckTypes();

        $this->command?->info($created > 0
            ? "Truck types created: {$created}."
            : 'Truck types already exist; left unchanged.');
    }
}
