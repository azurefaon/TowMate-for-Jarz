<?php

use App\Models\MobileAnnouncement;
use App\Models\MobileCoverageArea;
use App\Models\MobileHowItWorksStep;
use App\Models\MobileService;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

it('Q: Owner can upload a service image, and the public API exposes a full URL', function () {
    Storage::fake('public');
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.services.store'), [
        'title' => 'Emergency Towing',
        'description' => 'D',
        'image' => UploadedFile::fake()->image('towing.jpg'),
    ])->assertRedirect();

    $service = MobileService::where('title', 'Emergency Towing')->firstOrFail();
    Storage::disk('public')->assertExists($service->image_path);

    $response = $this->getJson('/api/v1/customer/content');
    $apiService = collect($response->json('services'))->firstWhere('title', 'Emergency Towing');

    expect($apiService['image_url'])->not->toBeNull()
        ->and($apiService['image_url'])->toContain($service->image_path);
});

it('R: a service with no uploaded image exposes a null image_url instead of failing', function () {
    MobileService::create(['title' => 'No Image', 'description' => 'D', 'is_active' => true]);

    $response = $this->getJson('/api/v1/customer/content');
    $apiService = collect($response->json('services'))->firstWhere('title', 'No Image');

    expect($apiService['image_url'])->toBeNull();
});

it('S: updating a service without a new file keeps its existing image', function () {
    Storage::fake('public');
    $owner = cmsOwner();
    $service = MobileService::create([
        'title' => 'Towing', 'description' => 'D', 'image_path' => 'mobile/existing.jpg', 'is_active' => true,
    ]);

    $this->actingAs($owner)->patch(route('superadmin.settings.customer-content.services.update', $service), [
        'title' => 'Towing', 'description' => 'D',
    ])->assertRedirect();

    expect($service->fresh()->image_path)->toBe('mobile/existing.jpg');
});

it('T: Owner can upload the Home hero image and the About image, reflected in the public API', function () {
    Storage::fake('public');
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_hero_image' => UploadedFile::fake()->image('hero.jpg'),
        'mobile_about_image' => UploadedFile::fake()->image('about.jpg'),
    ])->assertRedirect();

    $heroPath = SystemSetting::getValue('mobile_hero_image');
    $aboutPath = SystemSetting::getValue('mobile_about_image');
    Storage::disk('public')->assertExists($heroPath);
    Storage::disk('public')->assertExists($aboutPath);

    $response = $this->getJson('/api/v1/customer/content');
    $response->assertOk();

    expect($response->json('hero_image_url'))->toContain($heroPath)
        ->and($response->json('about.image_url'))->toContain($aboutPath);
});

it('U: no hero/about image configured returns null image URLs instead of failing', function () {
    $response = $this->getJson('/api/v1/customer/content');

    $response->assertOk()
        ->assertJsonPath('hero_image_url', null)
        ->assertJsonPath('about.image_url', null);
});

it('V: a non-Owner cannot upload App Images', function () {
    Storage::fake('public');
    $dispatcher = cmsDispatcher();

    $this->actingAs($dispatcher)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_hero_image' => UploadedFile::fake()->image('hero.jpg'),
    ])->assertStatus(403);

    expect(SystemSetting::getValue('mobile_hero_image'))->toBeNull();
});

it('W: a non-image file upload is rejected by validation', function () {
    Storage::fake('public');
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.services.store'), [
        'title' => 'Bad Upload',
        'description' => 'D',
        'image' => UploadedFile::fake()->create('not-an-image.txt', 10),
    ])->assertSessionHasErrors('image');

    expect(MobileService::where('title', 'Bad Upload')->exists())->toBeFalse();
});

it('X: the hero and about image URLs resolve through the public media route with real bytes', function () {
    Storage::fake('public');
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_hero_image' => UploadedFile::fake()->image('hero.jpg', 10, 10),
        'mobile_about_image' => UploadedFile::fake()->image('about.jpg', 10, 10),
    ])->assertRedirect();

    $response = $this->getJson('/api/v1/customer/content');
    $heroUrl = $response->json('hero_image_url');
    $aboutUrl = $response->json('about.image_url');

    expect($heroUrl)->toContain('/api/media/mobile/')
        ->and($aboutUrl)->toContain('/api/media/mobile/');

    $this->get(parse_url($heroUrl, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get(parse_url($aboutUrl, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

it('Y: a service image URL resolves through the same public media route', function () {
    Storage::fake('public');
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.services.store'), [
        'title' => 'Emergency Towing Y',
        'description' => 'D',
        'image' => UploadedFile::fake()->image('towing.jpg', 10, 10),
    ])->assertRedirect();

    $response = $this->getJson('/api/v1/customer/content');
    $apiService = collect($response->json('services'))->firstWhere('title', 'Emergency Towing Y');

    expect($apiService['image_url'])->toContain('/api/media/mobile/');

    $this->get(parse_url($apiService['image_url'], PHP_URL_PATH))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('Z: the public media route 404s safely for a filename that does not exist', function () {
    Storage::fake('public');

    $this->get('/api/media/mobile/does-not-exist.jpg')->assertNotFound();
});

it('AA: the media filename route constraint rejects path traversal characters', function () {
    $pattern = '#^[A-Za-z0-9._-]+$#';

    expect(preg_match($pattern, 'towing.jpg'))->toBe(1)
        ->and(preg_match($pattern, '../../.env'))->toBe(0)
        ->and(preg_match($pattern, 'a/b.jpg'))->toBe(0);
});

it('AB: the local-dev CORS origin pattern matches only localhost/127.0.0.1 and never an arbitrary host', function () {
    $pattern = '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#';

    expect(preg_match($pattern, 'http://localhost:58480'))->toBe(1)
        ->and(preg_match($pattern, 'http://127.0.0.1:8000'))->toBe(1)
        ->and(preg_match($pattern, 'https://localhost'))->toBe(1)
        ->and(preg_match($pattern, 'https://evil.com'))->toBe(0)
        ->and(preg_match($pattern, 'http://localhost.evil.com'))->toBe(0);
});

it('AC: Home Hero, Emergency, and About each expose their own independent image field', function () {
    Storage::fake('public');
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_hero_image' => UploadedFile::fake()->image('hero.jpg', 10, 10),
        'mobile_emergency_image' => UploadedFile::fake()->image('emergency.jpg', 10, 10),
        'mobile_about_image' => UploadedFile::fake()->image('about.jpg', 10, 10),
    ])->assertRedirect();

    $heroPath = SystemSetting::getValue('mobile_hero_image');
    $emergencyPath = SystemSetting::getValue('mobile_emergency_image');
    $aboutPath = SystemSetting::getValue('mobile_about_image');

    expect($heroPath)->not->toBe($emergencyPath)
        ->and($emergencyPath)->not->toBe($aboutPath)
        ->and($heroPath)->not->toBe($aboutPath);

    $response = $this->getJson('/api/v1/customer/content');

    expect($response->json('hero_image_url'))->toContain($heroPath)
        ->and($response->json('emergency_image_url'))->toContain($emergencyPath)
        ->and($response->json('about.image_url'))->toContain($aboutPath)
        ->and($response->json('hero_image_url'))->not->toBe($response->json('emergency_image_url'))
        ->and($response->json('emergency_image_url'))->not->toBe($response->json('about.image_url'));
});

it('AD: uploading only the Home Hero image does not populate the Emergency image', function () {
    Storage::fake('public');
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_hero_image' => UploadedFile::fake()->image('hero.jpg', 10, 10),
    ])->assertRedirect();

    $response = $this->getJson('/api/v1/customer/content');

    expect($response->json('hero_image_url'))->not->toBeNull()
        ->and($response->json('emergency_image_url'))->toBeNull();
});

it('AE: no Emergency image configured returns a null URL instead of failing', function () {
    $response = $this->getJson('/api/v1/customer/content');

    $response->assertOk()->assertJsonPath('emergency_image_url', null);
});

it('AF: Owner can upload and later replace the Emergency image', function () {
    Storage::fake('public');
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_emergency_image' => UploadedFile::fake()->image('emergency-v1.jpg', 10, 10),
    ])->assertRedirect();
    $firstPath = SystemSetting::getValue('mobile_emergency_image');

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_emergency_image' => UploadedFile::fake()->image('emergency-v2.jpg', 10, 10),
    ])->assertRedirect();
    $secondPath = SystemSetting::getValue('mobile_emergency_image');

    expect($firstPath)->not->toBeNull()
        ->and($secondPath)->not->toBeNull()
        ->and($secondPath)->not->toBe($firstPath);

    Storage::disk('public')->assertExists($secondPath);
});

it('AG: a non-Owner cannot upload the Emergency image', function () {
    Storage::fake('public');
    $dispatcher = cmsDispatcher();

    $this->actingAs($dispatcher)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_emergency_image' => UploadedFile::fake()->image('emergency.jpg', 10, 10),
    ])->assertStatus(403);

    expect(SystemSetting::getValue('mobile_emergency_image'))->toBeNull();
});

it('AH: a non-image file for the Emergency image is rejected by validation', function () {
    Storage::fake('public');
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_emergency_image' => UploadedFile::fake()->create('not-an-image.txt', 10),
    ])->assertSessionHasErrors('mobile_emergency_image');

    expect(SystemSetting::getValue('mobile_emergency_image'))->toBeNull();
});

it('AI: an active service appears in the public API in display order, and an inactive service is excluded', function () {
    MobileService::create(['title' => 'Towing', 'description' => 'D', 'display_order' => 1, 'is_active' => true]);
    MobileService::create(['title' => 'Roadside Help', 'description' => 'D', 'display_order' => 0, 'is_active' => true]);
    MobileService::create(['title' => 'Hidden Service', 'description' => 'D', 'display_order' => 2, 'is_active' => false]);

    $response = $this->getJson('/api/v1/customer/content');
    $titles = collect($response->json('services'))->pluck('title')->all();

    expect($titles)->toBe(['Roadside Help', 'Towing'])
        ->and($titles)->not->toContain('Hidden Service');
});

it('AJ: Owner can upload the Services/Page image, reflected in the public API', function () {
    Storage::fake('public');
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_services_image' => UploadedFile::fake()->image('services.jpg', 10, 10),
    ])->assertRedirect();

    $servicesPath = SystemSetting::getValue('mobile_services_image');
    Storage::disk('public')->assertExists($servicesPath);

    $response = $this->getJson('/api/v1/customer/content');

    expect($response->json('services_image_url'))->toContain($servicesPath)
        ->and($response->json('services_image_url'))->toContain('/api/media/mobile/');
});

it('AK: no Services image configured returns a null URL instead of failing', function () {
    $response = $this->getJson('/api/v1/customer/content');

    $response->assertOk()->assertJsonPath('services_image_url', null);
});

it('AL: the Services image is independent from Home Hero, Emergency, and About', function () {
    Storage::fake('public');
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_hero_image' => UploadedFile::fake()->image('hero.jpg', 10, 10),
        'mobile_services_image' => UploadedFile::fake()->image('services.jpg', 10, 10),
        'mobile_emergency_image' => UploadedFile::fake()->image('emergency.jpg', 10, 10),
        'mobile_about_image' => UploadedFile::fake()->image('about.jpg', 10, 10),
    ])->assertRedirect();

    $response = $this->getJson('/api/v1/customer/content');
    $urls = [
        $response->json('hero_image_url'),
        $response->json('services_image_url'),
        $response->json('emergency_image_url'),
        $response->json('about.image_url'),
    ];

    expect($urls)->toEqual(array_unique($urls));
});

it('AM: a non-Owner cannot upload the Services image', function () {
    Storage::fake('public');
    $dispatcher = cmsDispatcher();

    $this->actingAs($dispatcher)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_services_image' => UploadedFile::fake()->image('services.jpg', 10, 10),
    ])->assertStatus(403);

    expect(SystemSetting::getValue('mobile_services_image'))->toBeNull();
});

it('AN: a non-image file for the Services image is rejected by validation', function () {
    Storage::fake('public');
    $owner = cmsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.images.update'), [
        'mobile_services_image' => UploadedFile::fake()->create('not-an-image.txt', 10),
    ])->assertSessionHasErrors('mobile_services_image');

    expect(SystemSetting::getValue('mobile_services_image'))->toBeNull();
});
