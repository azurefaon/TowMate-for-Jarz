<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;

function olpRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function olpOwner(): User
{
    olpRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function olpDispatcher(): User
{
    olpRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

it('renders the Legal & Policies tab on the Business Settings page', function () {
    $response = $this->actingAs(olpOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('Legal &amp; Policies', false);
    $response->assertSee('data-tab="legal-policies"', false);
    $response->assertSee('id="legal-policies"', false);
});

it('dispatcher cannot reach the Business Settings page at all, including the legal tab', function () {
    $this->actingAs(olpDispatcher())
        ->get(route('superadmin.settings.index'))
        ->assertForbidden();
});

it('renders the current terms/privacy version and content values in the form fields', function () {
    SystemSetting::setValue('terms_of_use_version', '2.0');
    SystemSetting::setValue('terms_of_use_content', 'Existing terms body.');
    SystemSetting::setValue('privacy_policy_version', '3.0');
    SystemSetting::setValue('privacy_policy_content', 'Existing privacy body.');

    $response = $this->actingAs(olpOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('value="2.0"', false);
    $response->assertSee('Existing terms body.');
    $response->assertSee('value="3.0"', false);
    $response->assertSee('Existing privacy body.');
});

it('publishing a new Terms of Use version persists both fields and logs an audit event', function () {
    $owner = olpOwner();

    $response = $this->actingAs($owner)->post(route('superadmin.settings.update'), [
        'settings' => [
            'legal_terms_form' => '1',
            'terms_of_use_version' => '2.1',
            'terms_of_use_content' => "Updated intro.\n\n## New Section\nNew body.",
        ],
    ]);

    $response->assertRedirect();
    expect(SystemSetting::getValue('terms_of_use_version'))->toBe('2.1');
    expect(SystemSetting::getValue('terms_of_use_content'))->toContain('New Section');

    $log = AuditLog::where('action', 'terms_of_use_updated')->latest('id')->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($owner->id);
    expect($log->description)->toContain('2.1');
});

it('publishing a new Privacy Policy version persists both fields and logs an audit event', function () {
    $owner = olpOwner();

    $response = $this->actingAs($owner)->post(route('superadmin.settings.update'), [
        'settings' => [
            'legal_privacy_form' => '1',
            'privacy_policy_version' => '5.2',
            'privacy_policy_content' => 'Updated privacy body.',
        ],
    ]);

    $response->assertRedirect();
    expect(SystemSetting::getValue('privacy_policy_version'))->toBe('5.2');
    expect(SystemSetting::getValue('privacy_policy_content'))->toBe('Updated privacy body.');

    $log = AuditLog::where('action', 'privacy_policy_updated')->latest('id')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('5.2');
});

it('submitting the Terms of Use form does not touch the Privacy Policy settings, and vice versa', function () {
    SystemSetting::setValue('privacy_policy_version', '9.9');
    SystemSetting::setValue('privacy_policy_content', 'Untouched privacy body.');

    $this->actingAs(olpOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'legal_terms_form' => '1',
            'terms_of_use_version' => '1.5',
            'terms_of_use_content' => 'Only terms changed.',
        ],
    ])->assertRedirect();

    expect(SystemSetting::getValue('privacy_policy_version'))->toBe('9.9');
    expect(SystemSetting::getValue('privacy_policy_content'))->toBe('Untouched privacy body.');
});

it('rejects publishing the Terms of Use form without a version', function () {
    $this->actingAs(olpOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'legal_terms_form' => '1',
            'terms_of_use_content' => 'Body without a version.',
        ],
    ])->assertSessionHasErrors('settings.terms_of_use_version');

    expect(SystemSetting::getValue('terms_of_use_content'))->not->toBe('Body without a version.');
});

it('rejects publishing the Privacy Policy form without body content', function () {
    $this->actingAs(olpOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'legal_privacy_form' => '1',
            'privacy_policy_version' => '1.1',
        ],
    ])->assertSessionHasErrors('settings.privacy_policy_content');
});

it('does not require terms/privacy fields when an unrelated settings form is submitted', function () {
    $this->actingAs(olpOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'vat_rate_percentage' => '12',
        ],
    ])->assertSessionHasNoErrors();
});

it('preserves the legal-policies tab query string when redirecting back after publishing', function () {
    $owner = olpOwner();
    $referer = route('superadmin.settings.index', ['tab' => 'legal-policies']);

    $this->actingAs($owner)
        ->from($referer)
        ->post(route('superadmin.settings.update'), [
            'settings' => [
                'legal_terms_form' => '1',
                'terms_of_use_version' => '1.2',
                'terms_of_use_content' => 'Body.',
            ],
        ])
        ->assertRedirect($referer);
});
