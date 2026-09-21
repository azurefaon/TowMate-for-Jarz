<?php

use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Models\VehicleType;

function vgcRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function vgcOwner(): User
{
    return User::factory()->create(['role_id' => vgcRole(1, 'Owner')->id, 'status' => 'active', 'must_change_password' => false]);
}

function vgcTruckType(array $overrides = []): TruckType
{
    return TruckType::create(array_merge([
        'name' => 'VGC Truck ' . fake()->unique()->word(),
        'class' => 'light',
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'max_tonnage' => 4500,
        'status' => 'active',
    ], $overrides));
}

function vgcVehicleType(array $overrides = []): VehicleType
{
    return VehicleType::create(array_merge([
        'name' => 'VGC Vehicle ' . fake()->unique()->word(),
        'category' => 'cars_suvs',
        'weight_kg' => 1500,
        'status' => 'active',
        'display_order' => 0,
    ], $overrides));
}

function vgcCssRule(string $selector): string
{
    $css = file_get_contents(public_path('admin/css/vehicle-types.css'));
    preg_match('/' . preg_quote($selector, '/') . '\s*\{[^}]*\}/s', $css, $matches);

    return $matches[0] ?? '';
}

function vgcFontWeight(string $css, string $selector): int
{
    preg_match_all('/' . preg_quote($selector, '/') . '\s*\{[^}]*\}/s', $css, $matches);

    foreach ($matches[0] ?? [] as $rule) {
        if (preg_match('/font-weight:\s*(\d+)/', $rule, $weight)) {
            return (int) $weight[1];
        }
    }

    return 0;
}

it('defines a right-pointing chevron for the collapsed state on every expandable row type', function () {
    $rule = vgcCssRule('.vc-group-trucktype > summary::after,
.vc-group-category > summary::after');

    expect($rule)->not->toBe('');
    expect($rule)->toContain('rotate(-45deg)');
    expect($rule)->toContain('content: ""');
});

it('defines a downward-pointing chevron for the expanded state on every expandable row type', function () {
    $rule = vgcCssRule('.vc-group-trucktype[open] > summary::after,
.vc-group-category[open] > summary::after');

    expect($rule)->not->toBe('');
    expect($rule)->toContain('rotate(45deg)');
});

it('the expanded-state chevron rule is keyed off the native open attribute, not custom JavaScript state', function () {
    $css = file_get_contents(public_path('admin/css/vehicle-types.css'));

    expect($css)->toContain('.vc-group-trucktype[open] > summary::after');
    expect($css)->toContain('.vc-group-category[open] > summary::after');
});

it('gives every expandable row a hover and focus state using the existing neutral palette', function () {
    $hoverRule = vgcCssRule('.vc-group-trucktype > summary:hover,
.vc-group-category > summary:hover');

    $focusRule = vgcCssRule('.vc-group-trucktype > summary:focus-visible,
.vc-group-category > summary:focus-visible');

    expect($hoverRule)->toContain('#f5f5f5');
    expect($focusRule)->toContain('outline');
});

it('distinguishes an expanded row from a collapsed one without a bright background or colored border', function () {
    $rule = vgcCssRule('.vc-group-trucktype[open] > summary,
.vc-group-category[open] > summary');

    expect($rule)->not->toBe('');
    expect($rule)->toContain('#fafafa');
    expect($rule)->not->toContain('border');
    expect($rule)->not->toContain('#facc15');
    expect($rule)->not->toContain('yellow');
});

it('does not animate the chevron or row background', function () {
    $rules = [
        vgcCssRule('.vc-group-trucktype > summary,
.vc-group-category > summary'),
        vgcCssRule('.vc-group-trucktype > summary:hover,
.vc-group-category > summary:hover'),
        vgcCssRule('.vc-group-trucktype[open] > summary,
.vc-group-category[open] > summary'),
        vgcCssRule('.vc-group-trucktype > summary::after,
.vc-group-category > summary::after'),
        vgcCssRule('.vc-group-trucktype[open] > summary::after,
.vc-group-category[open] > summary::after'),
    ];

    foreach ($rules as $rule) {
        expect($rule)->not->toBe('');
        expect($rule)->not->toContain('transition');
        expect($rule)->not->toContain('animation');
    }
});

it('indents category rows under their truck type and vehicle rows further under their category', function () {
    $categoriesWrapperRule = vgcCssRule('.vc-group-categories');
    $vehicleListRule = vgcCssRule('.vc-vehicle-list');

    expect($categoriesWrapperRule)->toContain('margin: 12px 0 0 12px');
    expect($vehicleListRule)->toContain('margin: 10px 0 0 12px');
});

it('keeps the vehicle name growing to push the count and chevron to the right edge', function () {
    $rule = vgcCssRule('.vc-group-trucktype > summary > span:first-child,
.vc-group-category > summary > span:first-child');

    expect($rule)->toContain('flex: 1');
});

it('gives truck type headings more visual weight than category headings, and both more than vehicle rows', function () {
    $css = file_get_contents(public_path('admin/css/vehicle-types.css'));

    $truckTypeWeight = vgcFontWeight($css, '.vc-group-trucktype > summary');
    $categoryWeight = vgcFontWeight($css, '.vc-group-category > summary');
    $vehicleRowWeight = vgcFontWeight($css, '.vc-vehicle-row .cell-main');

    expect($truckTypeWeight)->toBeGreaterThan($categoryWeight);
    expect($categoryWeight)->toBeGreaterThan($vehicleRowWeight);
});

it('keeps the row structure and vehicle count markup unchanged for the chevron to attach to', function () {
    $owner = vgcOwner();
    $truckType = vgcTruckType(['name' => 'VGC Chevron Truck']);
    vgcVehicleType(['name' => 'VGC Chevron Vehicle', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id]);

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->toContain('<details class="vc-group-trucktype" data-accordion-group="vc-main-trucktype"');
    expect($content)->toMatch('/<details class="vc-group-category" data-accordion-group="vc-main-category-\d+"/');
    expect($content)->toContain('<span class="vc-count">1</span>');
});

it('still expands only the matching group so its chevron is the only one flipped by a search', function () {
    $truckType = vgcTruckType(['name' => 'VGC Search Chevron Truck']);
    $matching = vgcVehicleType(['name' => 'VGC Chevron Findable', 'required_truck_type_id' => $truckType->id]);
    vgcVehicleType(['name' => 'VGC Chevron Other']);

    $response = test()->actingAs(vgcOwner())->get(route('superadmin.vehicle-types.index', ['search' => 'Chevron Findable']));
    $content = $response->getContent();

    $response->assertOk();
    $response->assertSee($matching->name);
    expect(substr_count($content, 'data-accordion-group="vc-main-trucktype" open'))->toBe(1);
});
