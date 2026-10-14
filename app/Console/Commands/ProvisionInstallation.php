<?php

namespace App\Console\Commands;

use App\Models\VehicleType;
use App\Services\InitialProvisioningService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit first-install provisioning: the Owner account and the starting
 * truck types. Run by hand once for a new environment; it is deliberately not
 * part of the release command.
 *
 * Insert-only and safe to run again: it never changes an existing Owner
 * (email, password, status, profile) or any existing truck type, and it never
 * prints a password.
 */
class ProvisionInstallation extends Command
{
    protected $signature = 'towmate:provision
        {--ask-password : Prompt for the Owner password instead of reading SUPERADMIN_PASSWORD}
        {--vehicle-types : Also load the starting vehicle types (only when there are none)}';

    protected $description = 'Create the initial Owner account and truck types for a new install (insert-only, never overwrites).';

    public function handle(InitialProvisioningService $provisioning): int
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('truck_types') || ! Schema::hasTable('roles')) {
            $this->error('The database is not migrated yet. Run: php artisan migrate --force');

            return self::FAILURE;
        }

        $status = self::SUCCESS;

        if ($provisioning->ownerExists()) {
            $this->line('Owner account: already exists, left unchanged.');
        } elseif (($password = $this->ownerPassword()) === null) {
            $this->error('Owner account: not created. Set SUPERADMIN_PASSWORD (at least ' . InitialProvisioningService::MIN_PASSWORD_LENGTH . ' characters) or run with --ask-password.');
            $status = self::FAILURE;
        } elseif ($problem = $provisioning->passwordProblem($password)) {
            $this->error("Owner account: not created. {$problem}");
            $status = self::FAILURE;
        } elseif ($provisioning->provisionOwner($provisioning->configuredOwnerEmail(), $provisioning->configuredOwnerName(), $password)) {
            $this->info('Owner account: created for ' . $provisioning->configuredOwnerEmail() . '.');
        } else {
            $this->error('Owner account: not created because that email is already used by another account.');
            $status = self::FAILURE;
        }

        $created = $provisioning->provisionTruckTypes();
        $this->line($created > 0 ? "Truck types: created {$created}." : 'Truck types: already exist, left unchanged.');

        if ($this->option('vehicle-types')) {
            if (VehicleType::query()->exists()) {
                $this->line('Vehicle types: already exist, left unchanged.');
            } else {
                $this->callSilent('db:seed', ['--class' => VehicleTypeSeeder::class, '--force' => true]);
                $this->line('Vehicle types: starting catalogue created.');
            }
        }

        return $status;
    }

    /** The Owner password from a hidden prompt or SUPERADMIN_PASSWORD; never generated. */
    protected function ownerPassword(): ?string
    {
        if ($this->option('ask-password')) {
            $password = (string) $this->secret('Owner password (input is hidden)');
            $confirm = (string) $this->secret('Confirm Owner password');

            return $password !== '' && hash_equals($password, $confirm) ? $password : null;
        }

        $password = (string) env('SUPERADMIN_PASSWORD');

        return $password !== '' ? $password : null;
    }
}
