<?php

use App\Console\Commands\SeedTlAssignedTeamDemo;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

it('seeds a demo team leader that can log in and sees a populated assigned team', function () {
    expect(Artisan::call('dev:seed-tl-assigned-team-demo'))->toBe(0);

    $login = $this->postJson('/api/login', [
        'email' => SeedTlAssignedTeamDemo::EMAIL,
        'password' => SeedTlAssignedTeamDemo::PASSWORD,
    ])->assertOk();

    $token = $login->json('token') ?? $login->json('data.token');
    expect($token)->not->toBeEmpty();

    $data = $this->withToken($token)->getJson('/api/v1/team-leader/task')->assertOk()->json('data');

    expect($data['status'])->toBe('assigned')
        ->and($data['assigned_team'])->toMatchArray([
            'team_leader_name' => 'Demo Team Leader',
            'driver_name' => 'Pedro Santos',
            'crew_names' => ['Maria Reyes', 'Jose Ramirez'],
            'unit_name' => 'Demo Unit 01',
            'plate_number' => 'DEMO 0001',
            'truck_class' => 'heavy',
        ]);
});

it('is idempotent and resets the task on rerun', function () {
    Artisan::call('dev:seed-tl-assigned-team-demo');
    $booking = Booking::where('assigned_team_leader_id', User::where('email', SeedTlAssignedTeamDemo::EMAIL)->value('id'))->first();
    $booking->update(['status' => 'on_the_way']);

    Artisan::call('dev:seed-tl-assigned-team-demo');

    expect(User::where('email', SeedTlAssignedTeamDemo::EMAIL)->count())->toBe(1)
        ->and(Unit::where('plate_number', 'DEMO 0001')->count())->toBe(1)
        ->and(Booking::where('assigned_team_leader_id', $booking->assigned_team_leader_id)->count())->toBe(1)
        ->and($booking->fresh()->status)->toBe('assigned');
});

it('refuses to run outside local/testing', function () {
    $app = app();
    $original = $app['env'];
    $app['env'] = 'production';
    try {
        expect(Artisan::call('dev:seed-tl-assigned-team-demo'))->toBe(1)
            ->and(User::where('email', SeedTlAssignedTeamDemo::EMAIL)->exists())->toBeFalse();
    } finally {
        $app['env'] = $original;
    }
});
