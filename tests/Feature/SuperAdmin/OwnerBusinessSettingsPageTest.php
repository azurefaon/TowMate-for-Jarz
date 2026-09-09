<?php

use App\Models\MobileAnnouncement;
use App\Models\MobileCoverageArea;
use App\Models\MobileHowItWorksStep;
use App\Models\MobileService;
use App\Models\Role;
use App\Models\User;

function bsRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function bsOwner(): User
{
    bsRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function bsDispatcher(): User
{
    bsRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

it('owner can access business settings', function () {
    $this->actingAs(bsOwner())->get(route('superadmin.settings.index'))->assertOk();
});

it('returns a real non-empty success message after updating business settings', function () {
    $response = $this->actingAs(bsOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'bank_name' => 'BS Test Bank',
        ],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(session('success'))->not->toBeNull();
    expect(session('success'))->not->toBe('');
    expect(session('success'))->toBeString();
});

it('dispatcher cannot access business settings', function () {
    $this->actingAs(bsDispatcher())->get(route('superadmin.settings.index'))->assertForbidden();
});

it('renders the pricing and payment primary tab', function () {
    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('Pricing &amp; Payment', false);
    $response->assertSee('id="user-limits"', false);
});

it('renders the customer app content primary tab', function () {
    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('Customer App Content');
    $response->assertSee('id="customer-content"', false);
});

it('keeps all five customer app content sections reachable', function () {
    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('id="mc-announcements"', false);
    $response->assertSee('id="mc-services"', false);
    $response->assertSee('id="mc-about-support"', false);
    $response->assertSee('id="mc-how-it-works"', false);
    $response->assertSee('id="mc-coverage-areas"', false);
    $response->assertSee('data-mc-section="mc-announcements"', false);
    $response->assertSee('data-mc-section="mc-services"', false);
    $response->assertSee('data-mc-section="mc-about-support"', false);
    $response->assertSee('data-mc-section="mc-how-it-works"', false);
    $response->assertSee('data-mc-section="mc-coverage-areas"', false);
});

it('renders payment details fields with their real field names', function () {
    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('name="settings[bank_name]"', false);
    $response->assertSee('name="settings[bank_account_name]"', false);
    $response->assertSee('name="settings[bank_account_number]"', false);
    $response->assertSee('name="settings[gcash_name]"', false);
    $response->assertSee('name="settings[gcash_number]"', false);
});

it('renders discount settings fields with their real field names', function () {
    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('name="settings[discount_percentage]"', false);
    $response->assertSee('name="settings[discount_reason]"', false);
});

it('keeps the pricing and payment form wired to the exact existing update route', function () {
    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('action="' . route('superadmin.settings.update') . '"', false);
    $response->assertSee('name="_token"', false);
});

it('keeps customer app content forms wired to their exact existing routes and methods', function () {
    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('action="' . route('superadmin.settings.customer-content.announcements.store') . '"', false);
    $response->assertSee('action="' . route('superadmin.settings.customer-content.services.store') . '"', false);
    $response->assertSee('action="' . route('superadmin.settings.customer-content.about.update') . '"', false);
    $response->assertSee('action="' . route('superadmin.settings.customer-content.support.update') . '"', false);
    $response->assertSee('action="' . route('superadmin.settings.customer-content.how-it-works.store') . '"', false);
    $response->assertSee('action="' . route('superadmin.settings.customer-content.coverage-areas.store') . '"', false);
});

it('renders a compact empty state for each customer app content section when no records exist', function () {
    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('No announcements yet.');
    $response->assertSee('No services yet.');
    $response->assertSee('No steps yet.');
    $response->assertSee('No coverage areas yet.');
});

it('renders existing announcements, services, steps, and coverage areas when records exist', function () {
    MobileAnnouncement::create(['title' => 'BS Announcement', 'message' => 'M', 'is_active' => true]);
    MobileService::create(['title' => 'BS Service', 'description' => 'D', 'display_order' => 0, 'is_active' => true]);
    MobileHowItWorksStep::create(['step_title' => 'BS Step', 'step_description' => 'D', 'display_order' => 0, 'is_active' => true]);
    MobileCoverageArea::create(['name' => 'BS Area', 'display_order' => 0, 'is_active' => true]);

    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('BS Announcement');
    $response->assertSee('BS Service');
    $response->assertSee('BS Step');
    $response->assertSee('BS Area');
    $response->assertSee('Active');
});

it('keeps the existing toggle and move actions available for populated content', function () {
    $service = MobileService::create(['title' => 'BS Toggle Service', 'description' => 'D', 'display_order' => 0, 'is_active' => true]);

    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('action="' . route('superadmin.settings.customer-content.services.toggle', $service) . '"', false);
    $response->assertSee('action="' . route('superadmin.settings.customer-content.services.move', $service) . '"', false);
});

it('does not introduce technical or system-admin controls', function () {
    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertDontSee('Upload New APK');
    $response->assertDontSee('Team Leader Capacity');
    $response->assertDontSee('Customer Inactivity Lock');
    $response->assertDontSee('API Key', false);
    $response->assertDontSee('SMTP', false);
});

it('does not introduce dispatcher or unit assignment controls', function () {
    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertDontSee('name="team_leader_id"', false);
    $response->assertDontSee('name="driver_id"', false);
    $response->assertDontSee('Assign Team Leader');
    $response->assertDontSee('Assign Unit');
});

it('exposes tab and subnav navigation markup for the client-side switcher', function () {
    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('class="settings-tabs"', false);
    $response->assertSee('class="mc-subnav"', false);
    $response->assertSee('data-tab="user-limits"', false);
    $response->assertSee('data-tab="customer-content"', false);
});

it('places the inline edit status before the save button so the button aligns right', function () {
    MobileService::create(['title' => 'BS Align Service', 'description' => 'D', 'display_order' => 0, 'is_active' => true]);

    $response = $this->actingAs(bsOwner())->get(route('superadmin.settings.index'));
    $content = $response->getContent();

    $response->assertOk();
    $statusPos = strpos($content, 'mc-status is-active');
    $buttonPos = strpos($content, '>Edit</button>');

    expect($statusPos)->not->toBeFalse();
    expect($buttonPos)->not->toBeFalse();
    expect($statusPos)->toBeLessThan($buttonPos);
});
