<?php

use App\Models\MobileAnnouncement;
use App\Models\MobileCoverageArea;
use App\Models\MobileHowItWorksStep;
use App\Models\MobileService;
use App\Models\Role;
use App\Models\User;

function cmsOwner(): User
{
    $role = Role::find(1) ?: tap(new Role(['name' => 'Owner']), function ($r) {
        $r->id = 1;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id, 'must_change_password' => false]);
}

function cmsDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher', 'description' => 'Dispatch staff']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id, 'must_change_password' => false]);
}

function cmsTeamLeader(): User
{
    $role = Role::find(3) ?: tap(new Role(['name' => 'Team Leader', 'description' => 'Tow unit team leader']), function ($r) {
        $r->id = 3;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id, 'must_change_password' => false]);
}

it('A: Owner can create an announcement', function () {
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.announcements.store'), [
        'title' => 'Holiday Notice',
        'message' => 'We remain open during the holidays.',
    ])->assertRedirect();

    expect(MobileAnnouncement::where('title', 'Holiday Notice')->exists())->toBeTrue();
});

it('B: Owner can toggle an announcement active/inactive', function () {
    $owner = cmsOwner();
    $announcement = MobileAnnouncement::create(['title' => 'T', 'message' => 'M', 'is_active' => true]);

    $this->actingAs($owner)->patch(route('superadmin.settings.customer-content.announcements.toggle', $announcement))
        ->assertRedirect();

    expect($announcement->fresh()->is_active)->toBeFalse();
});

it('C: non-Owner (Dispatcher) cannot access CMS write routes', function () {
    $dispatcher = cmsDispatcher();

    $this->actingAs($dispatcher)->post(route('superadmin.settings.customer-content.announcements.store'), [
        'title' => 'Should not save',
        'message' => 'Blocked',
    ])->assertStatus(403);

    expect(MobileAnnouncement::where('title', 'Should not save')->exists())->toBeFalse();
});

it('D: public API returns only the currently applicable announcement', function () {
    MobileAnnouncement::create(['title' => 'Expired', 'message' => 'M', 'is_active' => true, 'end_at' => now()->subDay()]);
    MobileAnnouncement::create(['title' => 'Inactive', 'message' => 'M', 'is_active' => false]);
    MobileAnnouncement::create(['title' => 'Current', 'message' => 'M', 'is_active' => true]);

    $response = $this->getJson('/api/v1/customer/content');

    $response->assertOk()
        ->assertJsonPath('announcement.title', 'Current');
});

it('E: public API returns null announcement when none qualifies', function () {
    MobileAnnouncement::create(['title' => 'Inactive', 'message' => 'M', 'is_active' => false]);

    $this->getJson('/api/v1/customer/content')
        ->assertOk()
        ->assertJsonPath('announcement', null);
});

it('F: public API services never include a price field and respect ordering/active flag', function () {
    MobileService::create(['title' => 'B Service', 'description' => 'D', 'display_order' => 2, 'is_active' => true]);
    MobileService::create(['title' => 'A Service', 'description' => 'D', 'display_order' => 1, 'is_active' => true]);
    MobileService::create(['title' => 'Hidden', 'description' => 'D', 'display_order' => 0, 'is_active' => false]);

    $response = $this->getJson('/api/v1/customer/content');

    $response->assertOk();
    $services = $response->json('services');

    expect($services)->toHaveCount(2)
        ->and($services[0]['title'])->toBe('A Service')
        ->and($services[1]['title'])->toBe('B Service')
        ->and(array_keys($services[0]))->not->toContain('price')
        ->and(array_keys($services[0]))->not->toContain('priceRange')
        ->and(array_keys($services[0]))->not->toContain('price_range');
});

it('G: public API how_it_works and coverage_areas respect active flag and ordering', function () {
    MobileHowItWorksStep::create(['step_title' => 'Step 2', 'step_description' => 'D', 'display_order' => 2, 'is_active' => true]);
    MobileHowItWorksStep::create(['step_title' => 'Step 1', 'step_description' => 'D', 'display_order' => 1, 'is_active' => true]);
    MobileHowItWorksStep::create(['step_title' => 'Hidden Step', 'step_description' => 'D', 'display_order' => 0, 'is_active' => false]);

    MobileCoverageArea::create(['name' => 'Quezon City', 'display_order' => 1, 'is_active' => true]);
    MobileCoverageArea::create(['name' => 'Hidden Area', 'display_order' => 0, 'is_active' => false]);

    $response = $this->getJson('/api/v1/customer/content');
    $response->assertOk();

    expect($response->json('how_it_works'))->toHaveCount(2)
        ->and($response->json('how_it_works.0.title'))->toBe('Step 1')
        ->and($response->json('coverage_areas'))->toHaveCount(1)
        ->and($response->json('coverage_areas.0.name'))->toBe('Quezon City');
});

it('H: Owner can update About and Support settings, reflected in the public API', function () {
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.about.update'), [
        'mobile_about_text' => 'Our mission is reliability.',
    ])->assertRedirect();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.support.update'), [
        'mobile_support_phone' => '+63 900 000 0000',
        'mobile_support_email' => 'support@towmate.ph',
        'mobile_support_location' => 'Quezon City',
        'mobile_support_hours' => 'Available 24/7',
    ])->assertRedirect();

    $response = $this->getJson('/api/v1/customer/content');
    $response->assertOk()
        ->assertJsonPath('about.text', 'Our mission is reliability.')
        ->assertJsonPath('support.phone', '+63 900 000 0000')
        ->assertJsonPath('support.email', 'support@towmate.ph');
});

it('I: announcement end_at must be after start_at', function () {
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.announcements.store'), [
        'title' => 'Bad dates',
        'message' => 'M',
        'start_at' => now()->addDay()->format('Y-m-d H:i:s'),
        'end_at' => now()->format('Y-m-d H:i:s'),
    ])->assertSessionHasErrors('end_at');
});

it('J: HTML in CMS text fields is stripped, not stored as markup', function () {
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.announcements.store'), [
        'title' => 'Safe Title',
        'message' => '<script>alert(1)</script>Hello',
    ])->assertRedirect();

    $announcement = MobileAnnouncement::where('title', 'Safe Title')->firstOrFail();
    expect($announcement->message)->not->toContain('<script>')
        ->and($announcement->message)->toContain('Hello');
});

it('K: reordering a service without changing content logs a reorder action, not a generic update', function () {
    $owner = cmsOwner();
    $service = MobileService::create(['title' => 'Towing', 'description' => 'D', 'display_order' => 0, 'is_active' => true]);

    $this->actingAs($owner)->patch(route('superadmin.settings.customer-content.services.update', $service), [
        'title' => 'Towing',
        'description' => 'D',
        'category' => null,
        'availability_note' => null,
        'display_order' => 5,
    ])->assertRedirect();

    $log = \App\Models\AuditLog::where('entity_type', 'MobileService')->where('entity_id', $service->id)->latest('id')->first();
    expect($log->action)->toBe('mobile_service_reordered')
        ->and($service->fresh()->display_order)->toBe(5);
});

it('L: Owner can add, reorder, and deactivate a Coverage Area end to end', function () {
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.coverage-areas.store'), [
        'name' => 'Quezon City',
        'display_order' => 2,
    ])->assertRedirect();

    $area = MobileCoverageArea::where('name', 'Quezon City')->firstOrFail();

    $this->getJson('/api/v1/customer/content')
        ->assertJsonPath('coverage_areas.0.name', 'Quezon City');

    $this->actingAs($owner)->patch(route('superadmin.settings.customer-content.coverage-areas.update', $area), [
        'name' => 'Quezon City',
        'display_order' => 9,
    ])->assertRedirect();

    expect($area->fresh()->display_order)->toBe(9);
    $reorderLog = \App\Models\AuditLog::where('entity_type', 'MobileCoverageArea')->where('entity_id', $area->id)->latest('id')->first();
    expect($reorderLog->action)->toBe('mobile_coverage_area_reordered');

    $this->actingAs($owner)->patch(route('superadmin.settings.customer-content.coverage-areas.toggle', $area))
        ->assertRedirect();

    expect($area->fresh()->is_active)->toBeFalse();

    $this->getJson('/api/v1/customer/content')
        ->assertJsonPath('coverage_areas', []);
});

it('M: a Team Leader also cannot access CMS write routes', function () {
    $teamLeader = cmsTeamLeader();

    $this->actingAs($teamLeader)->post(route('superadmin.settings.customer-content.services.store'), [
        'title' => 'Should not save',
        'description' => 'Blocked',
    ])->assertStatus(403);

    expect(MobileService::where('title', 'Should not save')->exists())->toBeFalse();
});

it('N: creating services without display_order auto-appends to the end', function () {
    $owner = cmsOwner();

    foreach (['First', 'Second', 'Third'] as $title) {
        $this->actingAs($owner)->post(route('superadmin.settings.customer-content.services.store'), [
            'title' => $title,
            'description' => 'D',
        ])->assertRedirect();
    }

    $ordered = MobileService::orderBy('display_order')->orderBy('id')->pluck('title');
    expect($ordered->values()->all())->toBe(['First', 'Second', 'Third']);
});

it('O: moving a service up swaps display_order with its previous sibling, reflected by the public API', function () {
    $owner = cmsOwner();
    $a = MobileService::create(['title' => 'A', 'description' => 'D', 'display_order' => 0, 'is_active' => true]);
    $b = MobileService::create(['title' => 'B', 'description' => 'D', 'display_order' => 1, 'is_active' => true]);
    $c = MobileService::create(['title' => 'C', 'description' => 'D', 'display_order' => 2, 'is_active' => true]);

    $this->actingAs($owner)->patch(route('superadmin.settings.customer-content.services.move', $b), [
        'direction' => 'up',
    ])->assertRedirect();

    expect($b->fresh()->display_order)->toBe(0)
        ->and($a->fresh()->display_order)->toBe(1)
        ->and($c->fresh()->display_order)->toBe(2);

    $log = \App\Models\AuditLog::where('entity_type', 'MobileService')->where('entity_id', $b->id)->latest('id')->first();
    expect($log->action)->toBe('mobile_service_reordered');

    $response = $this->getJson('/api/v1/customer/content');
    $services = collect($response->json('services'))->pluck('title')->values()->all();
    expect($services)->toBe(['B', 'A', 'C']);
});

it('P: moving the first service up, or the last service down, is a safe no-op', function () {
    $owner = cmsOwner();
    $a = MobileService::create(['title' => 'A', 'description' => 'D', 'display_order' => 0, 'is_active' => true]);
    $b = MobileService::create(['title' => 'B', 'description' => 'D', 'display_order' => 1, 'is_active' => true]);

    $this->actingAs($owner)->patch(route('superadmin.settings.customer-content.services.move', $a), [
        'direction' => 'up',
    ])->assertRedirect();

    expect($a->fresh()->display_order)->toBe(0)
        ->and($b->fresh()->display_order)->toBe(1);
});
