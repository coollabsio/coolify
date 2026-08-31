<?php

use App\Livewire\Profile\Index;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Services\AvatarStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create([
        'id' => 0,
        'avatar_storage_type' => 'local',
    ]));
});

it('compresses and stores an uploaded profile picture on the configured local storage', function () {
    Storage::fake('images');
    $user = User::factory()->create(['name' => 'Test User']);
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->set('avatar', UploadedFile::fake()->image('profile.png', 1200, 900))
        ->call('uploadAvatar')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->avatar_path)
        ->toBe("avatars/{$user->id}/avatar.jpg")
        ->and($user->avatar_storage_type)->toBe('local')
        ->and($user->avatar_s3_storage_id)->toBeNull();

    Storage::disk('images')->assertExists($user->avatar_path);

    $image = getimagesizefromstring(Storage::disk('images')->get($user->avatar_path));

    expect($image[0])->toBeLessThanOrEqual(256)
        ->and($image[1])->toBeLessThanOrEqual(256)
        ->and($image['mime'])->toBe('image/jpeg')
        ->and(Storage::disk('images')->size($user->avatar_path))->toBeLessThan(100_000);
});

it('stores an already compressed browser JPEG without server image extensions', function () {
    Storage::fake('images');
    $user = User::factory()->create(['name' => 'Test User']);
    $contents = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2NjIpLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+qaKKKAP/9k=');
    $path = tempnam(sys_get_temp_dir(), 'avatar');
    file_put_contents($path, $contents);
    $upload = new UploadedFile($path, 'avatar.jpg', 'image/jpeg', null, true);

    app(AvatarStorageService::class)->store($user, $upload);

    expect(Storage::disk('images')->get("avatars/{$user->id}/avatar.jpg"))->toBe($contents);
});

it('serves the authenticated users profile picture', function () {
    Storage::fake('images');
    $user = User::factory()->create([
        'name' => 'Test User',
        'avatar_path' => 'avatars/1/avatar.jpg',
        'avatar_storage_type' => 'local',
    ]);
    Storage::disk('images')->put($user->avatar_path, 'avatar-content');

    $this->withoutMiddleware()->actingAs($user)
        ->get(route('profile.avatar'))
        ->assertSuccessful()
        ->assertHeader('content-type', 'image/jpeg');
});

it('removes the current profile picture', function () {
    Storage::fake('images');
    $user = User::factory()->create([
        'name' => 'Test User',
        'avatar_path' => 'avatars/1/avatar.jpg',
        'avatar_storage_type' => 'local',
    ]);
    Storage::disk('images')->put($user->avatar_path, 'avatar-content');
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->call('removeAvatar')
        ->assertHasNoErrors();

    expect($user->refresh()->avatar_path)->toBeNull();
    Storage::disk('images')->assertMissing('avatars/1/avatar.jpg');
});

it('falls back cleanly when the avatars S3 storage no longer exists', function () {
    $user = User::factory()->create([
        'name' => 'Test User',
        'avatar_path' => 'avatars/1/avatar.webp',
        'avatar_storage_type' => 's3',
        'avatar_s3_storage_id' => null,
    ]);

    $this->withoutMiddleware()->actingAs($user)
        ->get(route('profile.avatar'))
        ->assertNotFound();
});

it('automatically uploads a selected profile picture and keeps the current avatar until it succeeds', function () {
    $profile = file_get_contents(resource_path('views/livewire/profile/index.blade.php'));
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($profile)
        ->toContain("this.\$wire.upload('avatar', compressed")
        ->toContain('await this.$wire.uploadAvatar()')
        ->toContain('if (uploaded)')
        ->toContain('canvas.toBlob')
        ->toContain('x-ref="avatarInput"')
        ->toContain('class="hidden"')
        ->toContain("processing ? 'Uploading…' : 'Browse…'")
        ->not->toContain('wire:click="uploadAvatar"')
        ->not->toContain('Upload picture')
        ->not->toContain('type="file" x-on:change')
        ->and($menu)
        ->toContain("route('profile.avatar',");
});

it('offers runtime local or existing S3 profile picture storage', function () {
    $component = file_get_contents(app_path('Livewire/Settings/Advanced.php'));
    $view = file_get_contents(resource_path('views/livewire/settings/advanced.blade.php'));

    expect($component)
        ->toContain("['value' => 'local', 'label' => 'Local storage']")
        ->toContain("'value' => 's3:'.\$storage->id")
        ->toContain('->whereTeamId(0)')
        ->toContain("->where('is_usable', true)")
        ->and($view)
        ->toContain('id="avatar_storage"')
        ->toContain('Use S3 for multi-instance or cloud deployments');
});
