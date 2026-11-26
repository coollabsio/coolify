<?php

use App\Livewire\Upgrade;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('initializes latest version during mount from cached versions data', function () {
    config(['constants.coolify.version' => '4.0.0-beta.998']);
    InstanceSettings::forceCreate([
        'id' => 0,
        'new_version_available' => true,
    ]);

    Cache::shouldReceive('remember')
        ->once()
        ->with('coolify:versions', 3600, Mockery::type(Closure::class))
        ->andReturn([
            'coolify' => [
                'version' => '4.0.0-beta.999',
            ],
        ]);

    Livewire::test(Upgrade::class)
        ->assertSet('currentVersion', '4.0.0-beta.998')
        ->assertSet('latestVersion', '4.0.0-beta.999')
        ->assertSet('isUpgradeAvailable', true)
        ->assertSee('4.0.0-beta.998')
        ->assertSee('4.0.0-beta.999');
});

it('falls back to 0.0.0 during mount when cached versions data is unavailable', function () {
    InstanceSettings::forceCreate([
        'id' => 0,
        'new_version_available' => false,
    ]);

    Cache::shouldReceive('remember')
        ->once()
        ->with('coolify:versions', 3600, Mockery::type(Closure::class))
        ->andReturn(null);

    Livewire::test(Upgrade::class)
        ->assertSet('latestVersion', '0.0.0');
});

it('clears stale upgrade availability when current version already matches latest version', function () {
    config(['constants.coolify.version' => '4.0.0-beta.999']);
    InstanceSettings::forceCreate([
        'id' => 0,
        'new_version_available' => true,
    ]);

    Cache::shouldReceive('remember')
        ->once()
        ->with('coolify:versions', 3600, Mockery::type(Closure::class))
        ->andReturn([
            'coolify' => [
                'version' => '4.0.0-beta.999',
            ],
        ]);

    Livewire::test(Upgrade::class)
        ->assertSet('latestVersion', '4.0.0-beta.999')
        ->assertSet('isUpgradeAvailable', false);

    expect((bool) InstanceSettings::findOrFail(0)->new_version_available)->toBeFalse();
});

it('clears stale upgrade availability when current version is newer than cached latest version', function () {
    config(['constants.coolify.version' => '4.0.0-beta.1000']);
    InstanceSettings::forceCreate([
        'id' => 0,
        'new_version_available' => true,
    ]);

    Cache::shouldReceive('remember')
        ->once()
        ->with('coolify:versions', 3600, Mockery::type(Closure::class))
        ->andReturn([
            'coolify' => [
                'version' => '4.0.0-beta.999',
            ],
        ]);

    Livewire::test(Upgrade::class)
        ->assertSet('latestVersion', '4.0.0-beta.999')
        ->assertSet('isUpgradeAvailable', false);

    expect((bool) InstanceSettings::findOrFail(0)->new_version_available)->toBeFalse();
});

it('reports the upgrade as complete when the new version answers the status request of the old page', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $rootTeam->id]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id, 'private_key_id' => $privateKey->id]);
    InstanceSettings::forceCreate(['id' => 0, 'new_version_available' => true]);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);
    Cache::put('coolify:versions', ['coolify' => ['version' => '4.6.0']], 3600);

    // The page was rendered by the old version.
    config(['constants.coolify.version' => '4.5.0']);
    $component = Livewire::test(Upgrade::class)->assertSet('currentVersion', '4.5.0');

    // The restarted container runs the new version and reads the finished status file.
    config(['constants.coolify.version' => '4.6.0']);
    $statusLine = '6|Upgrade complete|'.now()->toIso8601String();
    Process::fake(['*' => Process::result(output: $statusLine)]);

    expect($component->instance()->getUpgradeStatus())
        ->toMatchArray(['status' => 'complete', 'running_version' => '4.6.0']);
});
