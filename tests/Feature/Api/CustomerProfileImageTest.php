<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function cpiRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cpiCustomer(array $overrides = []): User
{
    cpiRole(5, 'Customer');

    return User::factory()->create(array_merge([
        'role_id' => 5,
        'status' => 'active',
        'password' => Hash::make('CorrectHorse!9Battery'),
    ], $overrides));
}

beforeEach(function () {
    Storage::fake('profile_images');
});

it('has_profile_image is false and the image endpoint 404s when no photo has been uploaded', function () {
    $user = cpiCustomer();
    Sanctum::actingAs($user, ['*']);

    test()->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.has_profile_image', false);
    test()->getJson('/api/v1/profile/image')->assertStatus(404);
});

it('uploading a profile photo stores it on the persistent profile_images disk, not local/public', function () {
    $user = cpiCustomer();
    Sanctum::actingAs($user, ['*']);

    $response = test()->post('/api/v1/profile/image', [
        'profile_image' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $user->refresh();
    expect($user->profile_image)->not->toBeNull();
    Storage::disk('profile_images')->assertExists($user->profile_image);
});

it('the profile image endpoint serves the uploaded bytes back after upload', function () {
    $user = cpiCustomer();
    Sanctum::actingAs($user, ['*']);

    test()->post('/api/v1/profile/image', [
        'profile_image' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
    ])->assertOk();

    test()->getJson('/api/v1/profile')->assertJsonPath('data.has_profile_image', true);

    $response = test()->get('/api/v1/profile/image');
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('image');
});

it('replacing the photo deletes the old file and persists the new one', function () {
    $user = cpiCustomer();
    Sanctum::actingAs($user, ['*']);

    test()->post('/api/v1/profile/image', [
        'profile_image' => UploadedFile::fake()->image('first.jpg', 300, 300),
    ])->assertOk();
    $firstPath = $user->refresh()->profile_image;

    test()->post('/api/v1/profile/image', [
        'profile_image' => UploadedFile::fake()->image('second.jpg', 300, 300),
    ])->assertOk();
    $secondPath = $user->refresh()->profile_image;

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('profile_images')->assertMissing($firstPath);
    Storage::disk('profile_images')->assertExists($secondPath);
});

it('the picture survives a fresh authenticated session, proving it is not tied to any request/session-local state', function () {
    $user = cpiCustomer();

    Sanctum::actingAs($user, ['*']);
    test()->post('/api/v1/profile/image', [
        'profile_image' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
    ])->assertOk();

    // Simulate logout + a brand-new login session (fresh token, fresh request context).
    Sanctum::actingAs($user->fresh(), ['*']);

    test()->getJson('/api/v1/profile')->assertJsonPath('data.has_profile_image', true);
    test()->get('/api/v1/profile/image')->assertOk();
});

it('rejects a non-image file', function () {
    $user = cpiCustomer();
    Sanctum::actingAs($user, ['*']);

    $response = test()->post('/api/v1/profile/image', [
        'profile_image' => UploadedFile::fake()->create('not-an-image.txt', 10, 'text/plain'),
    ]);

    $response->assertStatus(422);
    expect($user->fresh()->profile_image)->toBeNull();
});

it('rejects an oversized image', function () {
    $user = cpiCustomer();
    Sanctum::actingAs($user, ['*']);

    $response = test()->post('/api/v1/profile/image', [
        'profile_image' => UploadedFile::fake()->image('huge.jpg')->size(6000),
    ]);

    $response->assertStatus(422);
});

it('an unauthenticated request cannot fetch or upload a profile image', function () {
    test()->getJson('/api/v1/profile/image')->assertStatus(401);
    test()->post('/api/v1/profile/image', [
        'profile_image' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
    ])->assertStatus(401);
});

it('one customer cannot fetch another customer\'s profile image', function () {
    $owner = cpiCustomer(['email' => 'cpi-owner@example.com']);
    $other = cpiCustomer(['email' => 'cpi-other@example.com']);

    Sanctum::actingAs($owner, ['*']);
    test()->post('/api/v1/profile/image', [
        'profile_image' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
    ])->assertOk();

    Sanctum::actingAs($other, ['*']);
    test()->getJson('/api/v1/profile')->assertJsonPath('data.has_profile_image', false);
    test()->get('/api/v1/profile/image')->assertStatus(404);
});

it('a failed storage write keeps the existing photo and does not report success', function () {
    $user = cpiCustomer();
    Sanctum::actingAs($user, ['*']);

    // A real local disk (throw=false, like production) whose writes can be made to fail.
    $root = storage_path('framework/testing/disks/cpi-failing');
    (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($root);
    $adapter = new class($root) extends \League\Flysystem\Local\LocalFilesystemAdapter
    {
        public bool $failWrites = false;

        public function writeStream(string $path, $contents, \League\Flysystem\Config $config): void
        {
            if ($this->failWrites) {
                throw \League\Flysystem\UnableToWriteFile::atLocation($path, 'simulated disk failure');
            }
            parent::writeStream($path, $contents, $config);
        }

        public function write(string $path, string $contents, \League\Flysystem\Config $config): void
        {
            if ($this->failWrites) {
                throw \League\Flysystem\UnableToWriteFile::atLocation($path, 'simulated disk failure');
            }
            parent::write($path, $contents, $config);
        }
    };
    $disk = new \Illuminate\Filesystem\FilesystemAdapter(
        new \League\Flysystem\Filesystem($adapter),
        $adapter,
        ['driver' => 'local', 'root' => $root, 'throw' => false],
    );
    Storage::set('profile_images', $disk);

    test()->post('/api/v1/profile/image', [
        'profile_image' => UploadedFile::fake()->image('first.jpg', 300, 300),
    ])->assertOk();
    $oldPath = $user->refresh()->profile_image;
    expect($disk->exists($oldPath))->toBeTrue();

    $adapter->failWrites = true;

    test()->post('/api/v1/profile/image', [
        'profile_image' => UploadedFile::fake()->image('second.jpg', 300, 300),
    ])->assertStatus(500)->assertJsonPath('success', false);

    expect($user->refresh()->profile_image)->toBe($oldPath);
    expect($disk->exists($oldPath))->toBeTrue();
    expect($disk->allFiles((string) $user->id))->toHaveCount(1);

    test()->get('/api/v1/profile/image')->assertOk();

    (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($root);
});
