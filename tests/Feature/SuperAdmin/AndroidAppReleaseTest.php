<?php

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function androidRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function androidOwner(): User
{
    androidRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function androidDispatcher(): User
{
    androidRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function fakeApkFile(string $name = 'app-release.apk', int $kilobytes = 50): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "PK\x03\x04" . str_repeat('A', $kilobytes * 1024));
}

beforeEach(function () {
    Storage::fake('apk_releases');
    config(['filesystems.legacy_apk_path' => storage_path('framework/testing/no-legacy-apk-' . uniqid() . '.apk')]);
});

it('rejects an apk upload from a guest', function () {
    $this->post(route('superadmin.settings.upload-apk'), [
        'apk_file' => fakeApkFile(),
    ])->assertRedirect();

    expect(SystemSetting::getValue('android_apk_filename'))->toBeNull();
});

it('rejects an apk upload from a non-owner role', function () {
    $this->actingAs(androidDispatcher())
        ->post(route('superadmin.settings.upload-apk'), ['apk_file' => fakeApkFile()])
        ->assertForbidden();

    expect(SystemSetting::getValue('android_apk_filename'))->toBeNull();
});

it('allows the owner to upload a valid apk', function () {
    $this->actingAs(androidOwner())
        ->post(route('superadmin.settings.upload-apk'), [
            'apk_file' => fakeApkFile(),
            'version_name' => '1.4.0',
            'release_notes' => 'Bug fixes.',
        ])
        ->assertRedirect()
        ->assertSessionHas('apk_success');

    expect(SystemSetting::getValue('android_apk_version_name'))->toBe('1.4.0');
    expect(SystemSetting::getValue('android_apk_release_notes'))->toBe('Bug fixes.');

    $filename = SystemSetting::getValue('android_apk_filename');
    expect($filename)->not->toBeNull();
    Storage::disk('apk_releases')->assertExists($filename);
});

it('rejects a non-apk file extension', function () {
    $this->actingAs(androidOwner())
        ->post(route('superadmin.settings.upload-apk'), [
            'apk_file' => UploadedFile::fake()->create('not-an-apk.txt', 10),
        ])
        ->assertSessionHasErrors(['apk_file']);

    expect(SystemSetting::getValue('android_apk_filename'))->toBeNull();
});

it('rejects a file with an apk extension but invalid package content', function () {
    $this->actingAs(androidOwner())
        ->post(route('superadmin.settings.upload-apk'), [
            'apk_file' => UploadedFile::fake()->create('fake.apk', 50),
        ])
        ->assertSessionHasErrors(['apk_file']);

    expect(SystemSetting::getValue('android_apk_filename'))->toBeNull();
});

it('rejects an oversized apk upload', function () {
    $this->actingAs(androidOwner())
        ->post(route('superadmin.settings.upload-apk'), [
            'apk_file' => UploadedFile::fake()->create('app-release.apk', 150000),
        ])
        ->assertSessionHasErrors(['apk_file']);

    expect(SystemSetting::getValue('android_apk_filename'))->toBeNull();
});

it('replaces the current apk and removes the previous file only after the new one is stored', function () {
    $owner = androidOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.upload-apk'), [
        'apk_file' => fakeApkFile('first.apk'),
        'version_name' => '1.0.0',
    ])->assertRedirect();

    $firstFilename = SystemSetting::getValue('android_apk_filename');
    Storage::disk('apk_releases')->assertExists($firstFilename);

    $this->actingAs($owner)->post(route('superadmin.settings.upload-apk'), [
        'apk_file' => fakeApkFile('second.apk'),
        'version_name' => '1.1.0',
    ])->assertRedirect();

    $secondFilename = SystemSetting::getValue('android_apk_filename');
    expect($secondFilename)->not->toBe($firstFilename);
    Storage::disk('apk_releases')->assertExists($secondFilename);
    Storage::disk('apk_releases')->assertMissing($firstFilename);
    expect(SystemSetting::getValue('android_apk_version_name'))->toBe('1.1.0');
});

it('does not touch the current apk when a replacement upload fails validation', function () {
    $owner = androidOwner();

    $this->actingAs($owner)->post(route('superadmin.settings.upload-apk'), [
        'apk_file' => fakeApkFile('good.apk'),
        'version_name' => '1.0.0',
    ])->assertRedirect();

    $goodFilename = SystemSetting::getValue('android_apk_filename');

    $this->actingAs($owner)->post(route('superadmin.settings.upload-apk'), [
        'apk_file' => UploadedFile::fake()->create('bad.apk', 10),
    ])->assertSessionHasErrors(['apk_file']);

    expect(SystemSetting::getValue('android_apk_filename'))->toBe($goodFilename);
    expect(SystemSetting::getValue('android_apk_version_name'))->toBe('1.0.0');
    Storage::disk('apk_releases')->assertExists($goodFilename);
});

it('serves the current uploaded apk from the stable public download route', function () {
    $owner = androidOwner();
    $this->actingAs($owner)->post(route('superadmin.settings.upload-apk'), [
        'apk_file' => fakeApkFile(),
        'version_name' => '2.0.0',
    ])->assertRedirect();

    $response = $this->get(route('download.android'));

    $response->assertOk();
    $response->assertHeader('content-disposition');
});

it('returns not found from the download route when no apk has been uploaded or configured', function () {
    $this->get(route('download.android'))->assertNotFound();
});

it('hides the download action on the login page when no apk is configured', function () {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertDontSee(route('download.android'), false);
});

it('shows the download action on the login page once the owner uploads an apk', function () {
    $owner = androidOwner();
    $this->actingAs($owner)->post(route('superadmin.settings.upload-apk'), [
        'apk_file' => fakeApkFile(),
    ])->assertRedirect();

    \Illuminate\Support\Facades\Auth::logout();
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertSee(route('download.android'), false);
});

it('renders the mobile app tab on the owner settings page', function () {
    $response = $this->actingAs(androidOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('Mobile App');
    $response->assertSee('id="mobile-app"', false);
    $response->assertSee('Upload Android App');
    $response->assertSee('action="' . route('superadmin.settings.upload-apk') . '"', false);
});

it('shows current apk metadata on the owner settings page after upload', function () {
    $owner = androidOwner();
    $this->actingAs($owner)->post(route('superadmin.settings.upload-apk'), [
        'apk_file' => fakeApkFile(),
        'version_name' => '3.2.1',
        'release_notes' => 'Improved stability.',
    ])->assertRedirect();

    $response = $this->actingAs($owner)->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('3.2.1');
    $response->assertSee('Improved stability.');
});

it('never exposes the internal storage filename or filesystem path on the settings page', function () {
    $owner = androidOwner();
    $this->actingAs($owner)->post(route('superadmin.settings.upload-apk'), [
        'apk_file' => fakeApkFile(),
    ])->assertRedirect();

    $filename = SystemSetting::getValue('android_apk_filename');

    $response = $this->actingAs($owner)->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertDontSee($filename);
    $response->assertDontSee(storage_path());
});

it('keeps the existing owner settings tabs and content working alongside the new mobile app tab', function () {
    $response = $this->actingAs(androidOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('Company Settings');
    $response->assertSee('Customer App Content');
    $response->assertSee('id="user-limits"', false);
    $response->assertSee('id="customer-content"', false);
});

it('keeps the existing public login page working alongside the android download section', function () {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertSee('Towing operations');
    $response->assertSee('Welcome back');
    $response->assertSee('iOS');
    $response->assertSee('Coming soon');
});
