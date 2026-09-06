<?php

use Illuminate\Support\Facades\Hash;

it('seeding the Super Admin without SUPERADMIN_PASSWORD set never uses the old hardcoded weak password', function () {
    expect(env('SUPERADMIN_PASSWORD'))->toBeNull();

    $this->seed(\Database\Seeders\SuperAdminSeeder::class);

    $email = strtolower(trim((string) env('SUPERADMIN_EMAIL', 'superadmin@gmail.com')));
    $user = \App\Models\User::where('email', $email)->first();

    expect($user)->not->toBeNull();
    expect(Hash::check('admin123456', $user->password))->toBeFalse();
});

it('seeding the Super Admin with SUPERADMIN_PASSWORD explicitly set uses that value', function () {
    putenv('SUPERADMIN_PASSWORD=ExplicitStrongPass123!');

    $this->seed(\Database\Seeders\SuperAdminSeeder::class);

    $email = strtolower(trim((string) env('SUPERADMIN_EMAIL', 'superadmin@gmail.com')));
    $user = \App\Models\User::where('email', $email)->first();

    expect(Hash::check('ExplicitStrongPass123!', $user->password))->toBeTrue();

    putenv('SUPERADMIN_PASSWORD');
});
