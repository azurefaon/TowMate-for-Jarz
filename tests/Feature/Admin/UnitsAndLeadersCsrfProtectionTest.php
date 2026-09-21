<?php

use Illuminate\Support\Facades\Route;

it('keeps every Units & Leaders mutating action behind CSRF protection', function () {
    $routeNames = [
        'admin.drivers.units.assign-team-leader',
        'admin.drivers.units.return-team-leader',
        'admin.drivers.units.remove-team-leader',
        'admin.drivers.units.assign-slot',
        'admin.drivers.units.remove-slot',
        'admin.drivers.loans.return',
        'admin.drivers.units.transfer-team',
        'admin.drivers.team-leaders.duty',
        'admin.drivers.units.slot-duty',
    ];

    foreach ($routeNames as $routeName) {
        $route = Route::getRoutes()->getByName($routeName);

        expect($route)->not->toBeNull();
        expect($route->methods())->toContain('POST');
        expect($route->gatherMiddleware())->toContain('web');
    }
});
