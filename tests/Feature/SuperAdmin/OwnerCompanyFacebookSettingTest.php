<?php

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;

function cfsRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cfsOwner(): User
{
    cfsRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function cfsDispatcher(): User
{
    cfsRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

it('renders the Facebook Page URL field in the Business Information section', function () {
    $response = $this->actingAs(cfsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('Facebook Page URL');
    $response->assertSee('name="settings[company_facebook_url]"', false);
});

it('owner can save a valid Facebook Page URL alongside the required business fields', function () {
    $owner = cfsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.update'), [
        'settings' => [
            'business_info_form' => '1',
            'company_name' => 'JARZ Towing Services',
            'company_email' => 'owner@example.com',
            'company_phone' => '0909 334 7549',
            'company_address' => 'Quezon City',
            'company_facebook_url' => 'https://www.facebook.com/profile.php?id=100090944122576',
        ],
    ])->assertRedirect();

    expect(SystemSetting::getValue('company_facebook_url'))
        ->toBe('https://www.facebook.com/profile.php?id=100090944122576');
});

it('rejects a Facebook Page URL that is not a valid http/https URL', function () {
    $owner = cfsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.update'), [
        'settings' => [
            'business_info_form' => '1',
            'company_name' => 'JARZ Towing Services',
            'company_email' => 'owner@example.com',
            'company_phone' => '0909 334 7549',
            'company_address' => 'Quezon City',
            'company_facebook_url' => 'not-a-url',
        ],
    ])->assertSessionHasErrors(['settings.company_facebook_url']);

    expect(SystemSetting::getValue('company_facebook_url'))->toBeNull();
});

it('allows the Facebook Page URL to be left blank since it is optional', function () {
    $owner = cfsOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.update'), [
        'settings' => [
            'business_info_form' => '1',
            'company_name' => 'JARZ Towing Services',
            'company_email' => 'owner@example.com',
            'company_phone' => '0909 334 7549',
            'company_address' => 'Quezon City',
        ],
    ])->assertSessionDoesntHaveErrors(['settings.company_facebook_url']);
});

it('does not allow a dispatcher to update the Facebook Page URL setting', function () {
    $dispatcher = cfsDispatcher();

    $this->actingAs($dispatcher)->post(route('superadmin.settings.update'), [
        'settings' => [
            'business_info_form' => '1',
            'company_name' => 'JARZ Towing Services',
            'company_email' => 'owner@example.com',
            'company_phone' => '0909 334 7549',
            'company_address' => 'Quezon City',
            'company_facebook_url' => 'https://www.facebook.com/someoneelse',
        ],
    ])->assertForbidden();

    expect(SystemSetting::getValue('company_facebook_url'))->toBeNull();
});
