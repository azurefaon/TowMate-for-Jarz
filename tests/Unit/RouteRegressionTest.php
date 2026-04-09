<?php

use App\Http\Controllers\Admin\AvailableUnitsController;
use App\Http\Controllers\Admin\JobsController;
use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class);

it('registers the role dashboard and support routes used across the app', function () {
    expect(Route::has('teamleader.dashboard'))->toBeTrue()
        ->and(Route::has('driver.dashboard'))->toBeTrue()
        ->and(Route::has('admin.drivers'))->toBeTrue()
        ->and(Route::has('admin.pending-bookings-count'))->toBeTrue()
        ->and(Route::has('customer.chat.show'))->toBeTrue();
});

it('renders the customer navbar without route generation errors', function () {
    $html = view('customer.components.navbar')->render();

    expect($html)->toContain(route('customer.track.index'));
});

it('uses a non-conflicting superadmin dashboard path', function () {
    expect(route('superadmin.dashboard', absolute: false))->toBe('/superadmin/dashboard');
});

it('registers the user archive management routes', function () {
    expect(Route::has('superadmin.users.archived'))->toBeTrue()
        ->and(Route::has('superadmin.users.archive'))->toBeTrue()
        ->and(Route::has('superadmin.users.restore'))->toBeTrue();
});

it('includes the dispatcher sidebar partial required by the admin layout', function () {
    expect(view()->exists('admin-dashboard.partials.sidebar'))->toBeTrue();

    $html = view('admin-dashboard.partials.sidebar')->render();

    expect($html)->toContain(route('admin.dashboard'))
        ->and($html)->toContain(route('admin.dispatch'))
        ->and($html)->toContain(route('admin.jobs'))
        ->and($html)->toContain(route('admin.drivers'))
        ->and($html)->toContain(route('admin.available-units'));
});

it('uses the admin jobs controller instead of a plain view route', function () {
    expect(Route::getRoutes()->getByName('admin.jobs')?->getActionName())
        ->toContain(JobsController::class);
});

it('uses the admin available units controller instead of the superadmin unit controller', function () {
    expect(Route::getRoutes()->getByName('admin.available-units')?->getActionName())
        ->toContain(AvailableUnitsController::class);
});
