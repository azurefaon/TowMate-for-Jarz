<?php

use App\Models\AuditLog;
use App\Models\MobileAnnouncement;
use App\Models\Role;
use App\Models\User;

function annRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function annUser(int $roleId, string $name): User
{
    annRole($roleId, $name);

    return User::factory()->create(['role_id' => $roleId, 'status' => 'active', 'must_change_password' => false]);
}

function annOwner(): User
{
    return annUser(1, 'Owner');
}

function annEnforceCsrf(): void
{
    app()->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, function ($app) {
        return new class ($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    });
}

function annMake(array $attrs = []): MobileAnnouncement
{
    return MobileAnnouncement::create($attrs + ['title' => 'Notice', 'message' => 'Body', 'is_active' => true]);
}

/* ---------- Delete ---------- */

it('lets the Owner delete one announcement, leaving the others alone, and logs it', function () {
    $owner = annOwner();
    $target = annMake(['title' => 'Delete me']);
    $other = annMake(['title' => 'Keep me']);

    $this->actingAs($owner)
        ->delete(route('superadmin.settings.customer-content.announcements.destroy', $target))
        ->assertRedirect()
        ->assertSessionHas('success', 'Announcement deleted.');

    expect(MobileAnnouncement::find($target->id))->toBeNull();
    expect(MobileAnnouncement::find($other->id))->not->toBeNull();

    $log = AuditLog::where('action', 'mobile_announcement_deleted')->first();
    expect($log)->not->toBeNull();
    expect($log->entity_id)->toBe($target->id);
    expect($log->reference)->toBe('Delete me');
    expect($log->user_id)->toBe($owner->id);
});

it('refuses announcement deletion for a Dispatcher and a guest', function () {
    $announcement = annMake();
    $url = route('superadmin.settings.customer-content.announcements.destroy', $announcement);

    $this->actingAs(annUser(2, 'Dispatcher'))->delete($url)->assertStatus(403);
    expect(MobileAnnouncement::find($announcement->id))->not->toBeNull();

    auth()->logout();
    $this->delete($url)->assertRedirect();
    expect(MobileAnnouncement::find($announcement->id))->not->toBeNull();
});

it('returns 404 when deleting an announcement that does not exist', function () {
    $this->actingAs(annOwner())
        ->delete(route('superadmin.settings.customer-content.announcements.destroy', 999999))
        ->assertNotFound();
});

it('does not allow announcement deletion over GET or POST', function () {
    $announcement = annMake();
    $url = route('superadmin.settings.customer-content.announcements.destroy', $announcement);
    $owner = annOwner();

    $this->actingAs($owner)->get($url)->assertStatus(405);
    $this->actingAs($owner)->post($url)->assertStatus(405);
    expect(MobileAnnouncement::find($announcement->id))->not->toBeNull();
});

it('enforces CSRF on announcement deletion', function () {
    $owner = annOwner();
    $announcement = annMake();
    $url = route('superadmin.settings.customer-content.announcements.destroy', $announcement);
    annEnforceCsrf();

    $this->actingAs($owner)->delete($url)->assertStatus(419);
    expect(MobileAnnouncement::find($announcement->id))->not->toBeNull();

    $this->actingAs($owner)
        ->withSession(['_token' => 'test-csrf-token'])
        ->delete($url, ['_token' => 'test-csrf-token'])
        ->assertRedirect();
    expect(MobileAnnouncement::find($announcement->id))->toBeNull();
});

/* ---------- Delete confirmation UI & layout ---------- */

it('renders a delete confirmation dialog with CSRF and DELETE method for each announcement', function () {
    $announcement = annMake(['title' => 'Holiday Notice']);

    $response = $this->actingAs(annOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('id="delete-announcement-modal-' . $announcement->id . '"', false);
    $response->assertSee('data-modal-target="delete-announcement-modal-' . $announcement->id . '"', false);
    $response->assertSee('Delete this announcement? This action cannot be undone.');
    $response->assertSee('action="' . route('superadmin.settings.customer-content.announcements.destroy', $announcement) . '"', false);
    $response->assertSee('name="_method" value="DELETE"', false);
});

it('renders one top action group with Edit, Activate/Deactivate and Delete, and no old bottom buttons', function () {
    $active = annMake(['title' => 'Is Active']);
    $off = annMake(['title' => 'Is Off', 'is_active' => false]);

    $response = $this->actingAs(annOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('class="mc-announcement-actions"', false);
    $response->assertSee('form="announcement-edit-' . $active->id . '"', false);
    $response->assertSee('form="announcement-toggle-' . $active->id . '"', false);
    $response->assertSee('Deactivate');
    $response->assertSee('Activate');
    // The old duplicated bottom placement (mc-row-actions) is gone for announcements.
    $html = $response->getContent();
    $section = substr($html, strpos($html, 'id="mc-announcements"'), strpos($html, 'id="mc-services"') - strpos($html, 'id="mc-announcements"'));
    expect($section)->not->toContain('mc-row-actions');
});

/* ---------- Status labels ---------- */

it('computes display status separately from the enabled flag', function () {
    expect(annMake(['is_active' => false])->displayStatus())->toBe('inactive');
    expect(annMake(['is_active' => false, 'end_at' => now()->subDay()])->displayStatus())->toBe('inactive');
    expect(annMake(['end_at' => now()->subMinute()])->displayStatus())->toBe('expired');
    expect(annMake(['start_at' => now()->addHour()])->displayStatus())->toBe('scheduled');
    expect(annMake(['start_at' => now()->subHour(), 'end_at' => now()->addHour()])->displayStatus())->toBe('active');
    expect(annMake()->displayStatus())->toBe('active');
});

it('keeps the status in step with the customer API eligibility', function () {
    $rows = [
        annMake(['title' => 'Inactive', 'is_active' => false]),
        annMake(['title' => 'Expired', 'end_at' => now()->subDay()]),
        annMake(['title' => 'Scheduled', 'start_at' => now()->addDay()]),
    ];

    foreach ($rows as $row) {
        expect($row->displayStatus())->not->toBe('active');
    }
    $this->getJson('/api/v1/customer/content')->assertOk()->assertJsonPath('announcement', null);

    $live = annMake(['title' => 'Live', 'start_at' => now()->subHour(), 'end_at' => now()->addHour()]);
    expect($live->displayStatus())->toBe('active');
    $this->getJson('/api/v1/customer/content')->assertJsonPath('announcement.title', 'Live');
});

it('shows Expired and Scheduled badges on the Owner page instead of Active', function () {
    annMake(['title' => 'Old Notice', 'end_at' => now()->subDays(2)]);
    annMake(['title' => 'Future Notice', 'start_at' => now()->addDays(2)]);

    $response = $this->actingAs(annOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('mc-status is-expired', false);
    $response->assertSee('mc-status is-scheduled', false);
    $response->assertDontSee('mc-status is-active', false);
    $response->assertSee('Ended ');
    $response->assertSee('Starts ');
});

it('shows Active and Inactive badges for plain announcements', function () {
    annMake(['title' => 'On']);
    annMake(['title' => 'Off', 'is_active' => false]);

    $response = $this->actingAs(annOwner())->get(route('superadmin.settings.index'));

    $response->assertSee('mc-status is-active', false);
    $response->assertSee('mc-status is-inactive', false);
});

/* ---------- Existing CRUD still works ---------- */

it('still creates, edits and toggles announcements', function () {
    $owner = annOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.customer-content.announcements.store'), [
        'title' => 'Created', 'message' => 'M',
    ])->assertRedirect();
    $announcement = MobileAnnouncement::where('title', 'Created')->firstOrFail();

    $this->actingAs($owner)->patch(route('superadmin.settings.customer-content.announcements.update', $announcement), [
        'title' => 'Edited', 'message' => 'M2',
    ])->assertRedirect();
    expect($announcement->fresh()->title)->toBe('Edited');

    $this->actingAs($owner)->patch(route('superadmin.settings.customer-content.announcements.toggle', $announcement))->assertRedirect();
    expect($announcement->fresh()->is_active)->toBeFalse();
});
