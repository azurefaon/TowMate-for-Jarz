<?php

namespace App\Services;

use App\Models\TruckType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * First-install provisioning: the owner account and the starting truck
 * types. Everything here is insert-only. Once a record exists, whatever the
 * owner has since changed (rates, tonnage, status, descriptions, email,
 * password, account status, profile) is left exactly as it is, so running it
 * again, or from a deploy, can never overwrite owner-managed settings.
 *
 * Nothing here returns, logs or prints a password.
 */
class InitialProvisioningService
{
    public const OWNER_ROLE_ID = 1;

    public const MIN_PASSWORD_LENGTH = 12;

    public const DEFAULT_OWNER_EMAIL = 'superadmin@gmail.com';

    public const DEFAULT_OWNER_NAME = 'System SuperAdmin';

    /** The starting catalogue, matching what earlier installs were seeded with. */
    public const DEFAULT_TRUCK_TYPES = [
        [
            'name' => 'Light Duty',
            'class' => 'light',
            'base_rate' => 1500.00,
            'per_km_rate' => 300.00,
            'max_tonnage' => 4.50,
            'description' => '4,500 kg and below — Sedans, hatchbacks, SUVs, motorcycles, pickups (unloaded), vans, MPVs.',
            'status' => 'active',
        ],
        [
            'name' => 'Medium Duty',
            'class' => 'medium',
            'base_rate' => 2500.00,
            'per_km_rate' => 300.00,
            'max_tonnage' => 7.50,
            'description' => '4,501–7,500 kg — Loaded pickups, small cargo trucks, minibuses, modern jeepneys, ambulances.',
            'status' => 'active',
        ],
        [
            'name' => 'Heavy Duty',
            'class' => 'heavy',
            'base_rate' => 4500.00,
            'per_km_rate' => 300.00,
            'max_tonnage' => 999.00,
            'description' => '7,501 kg and above — 10-wheelers, wing vans, trailer trucks, buses, heavy equipment.',
            'status' => 'active',
        ],
    ];

    /** Whether any Owner account exists, whatever its email or status. */
    public function ownerExists(): bool
    {
        return Schema::hasTable('users')
            && DB::table('users')->where('role_id', self::OWNER_ROLE_ID)->exists();
    }

    /** The configured owner email, falling back when the variable is unset or blank. */
    public function configuredOwnerEmail(): string
    {
        $email = strtolower(trim((string) env('SUPERADMIN_EMAIL')));

        return $email !== '' ? $email : self::DEFAULT_OWNER_EMAIL;
    }

    public function configuredOwnerName(): string
    {
        $name = trim((string) env('SUPERADMIN_NAME'));

        return $name !== '' ? $name : self::DEFAULT_OWNER_NAME;
    }

    public function passwordProblem(string $password): ?string
    {
        return mb_strlen($password) < self::MIN_PASSWORD_LENGTH
            ? 'The owner password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.'
            : null;
    }

    /**
     * Creates the first Owner account. Returns false, changing nothing, when
     * any Owner already exists or the email is already taken by another account.
     */
    public function provisionOwner(string $email, string $name, string $password): bool
    {
        if ($this->ownerExists() || DB::table('users')->where('email', $email)->exists()) {
            return false;
        }

        $now = now();
        $row = [
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role_id' => self::OWNER_ROLE_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('users', 'status')) {
            $row['status'] = 'active';
        }

        if (Schema::hasColumn('users', 'user_code')) {
            $row['user_code'] = $this->nextUserCode();
        }

        DB::table('users')->insert($row);

        return true;
    }

    /**
     * Creates the starting truck types, but only on an empty truck_types
     * table. An owner may have renamed, edited or disabled the originals, so
     * "missing by name" is not a safe test for "needs creating".
     *
     * @return int how many were created (0 when truck types already exist)
     */
    public function provisionTruckTypes(): int
    {
        if (! Schema::hasTable('truck_types') || TruckType::query()->exists()) {
            return 0;
        }

        foreach (self::DEFAULT_TRUCK_TYPES as $attributes) {
            TruckType::create($attributes);
        }

        return count(self::DEFAULT_TRUCK_TYPES);
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
