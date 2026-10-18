<?php

use App\Livewire\SwitchTeam;
use App\Livewire\Team\Index;
use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'avatar_storage_type' => 'local',
    ]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function giveTeamStoredAvatar(Team $team): string
{
    $path = "team-avatars/{$team->id}/avatar.jpg";
    $team->forceFill(['avatar_path' => $path, 'avatar_storage_type' => 'local'])->save();
    Storage::disk('images')->put($path, 'avatar-content');

    return $path;
}

it('stores a team avatar using the instance image storage setting', function () {
    Storage::fake('images');
    $upload = UploadedFile::fake()->createWithContent('team.jpg', file_get_contents(base_path('tests/Fixtures/project-icon.jpg')));

    Livewire::test(Index::class)
        ->set('avatar', $upload)
        ->call('uploadAvatar')
        ->assertHasNoErrors()
        ->assertDispatched('team-avatar-updated');

    $this->team->refresh();

    expect($this->team->avatar_path)->toBe("team-avatars/{$this->team->id}/avatar.jpg")
        ->and($this->team->avatar_storage_type)->toBe('local')
        ->and($this->team->avatar_s3_storage_id)->toBeNull();
    Storage::disk('images')->assertExists($this->team->avatar_path);
});

it('does not let a team member change the team avatar', function () {
    Storage::fake('images');
    $path = giveTeamStoredAvatar($this->team);
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Index::class)
        ->set('avatar', UploadedFile::fake()->createWithContent('team.jpg', file_get_contents(base_path('tests/Fixtures/project-icon.jpg'))))
        ->call('uploadAvatar')
        ->call('removeAvatar');

    expect($this->team->refresh()->avatar_path)->toBe($path);
    Storage::disk('images')->assertExists($path);
    expect(Storage::disk('images')->get($path))->toBe('avatar-content');
});

it('removes a team avatar', function () {
    Storage::fake('images');
    $path = giveTeamStoredAvatar($this->team);

    Livewire::test(Index::class)
        ->call('removeAvatar')
        ->assertHasNoErrors();

    expect($this->team->refresh()->avatar_path)->toBeNull();
    Storage::disk('images')->assertMissing($path);
});

it('serves a team avatar only to members of the team', function () {
    Storage::fake('images');
    giveTeamStoredAvatar($this->team);

    $this->get(route('team.avatar', ['team_id' => $this->team->id]))
        ->assertSuccessful()
        ->assertHeader('content-type', 'image/jpeg');

    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'owner']);
    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    $this->get(route('team.avatar', ['team_id' => $this->team->id]))
        ->assertNotFound();
});

it('serves the avatar of another team the user belongs to', function () {
    Storage::fake('images');
    $otherTeam = Team::factory()->create();
    $this->user->teams()->attach($otherTeam, ['role' => 'member']);
    giveTeamStoredAvatar($otherTeam);

    $this->get(route('team.avatar', ['team_id' => $otherTeam->id]))
        ->assertSuccessful();
});

it('deletes the avatar file when the team is deleted', function () {
    Storage::fake('images');
    $path = giveTeamStoredAvatar($this->team);

    $this->team->delete();

    Storage::disk('images')->assertMissing($path);
});

it('shows team avatars in the team switcher', function () {
    $otherTeam = Team::factory()->create(['name' => 'Zeta']);
    $this->user->teams()->attach($otherTeam, ['role' => 'member']);
    $otherTeam->forceFill(['avatar_path' => "team-avatars/{$otherTeam->id}/avatar.jpg", 'avatar_storage_type' => 'local'])->save();
    $this->user->load('teams');

    Livewire::test(SwitchTeam::class)
        ->assertSeeHtml('src="'.team_avatar_url($otherTeam).'"');
});

it('stores a team avatar on the instance S3 storage and serves it from the CDN', function () {
    Team::factory()->create(['id' => 0]);
    $storage = S3Storage::query()->create([
        'team_id' => 0,
        'name' => 'Avatar storage',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'avatars',
        'endpoint' => 'https://1.1.1.1',
        'is_usable' => true,
    ]);
    InstanceSettings::findOrFail(0)->update([
        'avatar_storage_type' => 's3',
        'avatar_s3_storage_id' => $storage->id,
        'image_cdn_url' => 'https://avatars.example.com/media',
    ]);
    $s3Disk = Storage::fake('team-avatar-s3');
    Storage::extend('s3', fn () => $s3Disk);
    Storage::fake('images');

    $upload = UploadedFile::fake()->createWithContent('team.jpg', file_get_contents(base_path('tests/Fixtures/project-icon.jpg')));

    Livewire::test(Index::class)
        ->set('avatar', $upload)
        ->call('uploadAvatar')
        ->assertHasNoErrors()
        ->assertNotDispatched('error');

    $this->team->refresh();
    expect($this->team->avatar_storage_type)->toBe('s3')
        ->and($this->team->avatar_s3_storage_id)->toBe($storage->id)
        ->and(team_avatar_url($this->team))->toBe("https://avatars.example.com/media/team-avatars/{$this->team->id}/avatar.jpg?v={$this->team->updated_at->timestamp}");
    $s3Disk->assertExists($this->team->avatar_path);
    Storage::disk('images')->assertMissing($this->team->avatar_path);
});
