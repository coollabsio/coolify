<?php

use App\Jobs\DockerCleanupJob;
use App\Livewire\Upgrade;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\CoolifyUpgradeDiskSpace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
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
        ->with('coolify:versions:all', 3600, Mockery::type(Closure::class))
        ->andReturn([
            'coolify' => [
                'v4' => [
                    'version' => '4.0.0-beta.999',
                ],
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
        ->with('coolify:versions:all', 3600, Mockery::type(Closure::class))
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
        ->with('coolify:versions:all', 3600, Mockery::type(Closure::class))
        ->andReturn([
            'coolify' => [
                'v4' => [
                    'version' => '4.0.0-beta.999',
                ],
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
        ->with('coolify:versions:all', 3600, Mockery::type(Closure::class))
        ->andReturn([
            'coolify' => [
                'v4' => [
                    'version' => '4.0.0-beta.999',
                ],
            ],
        ]);

    Livewire::test(Upgrade::class)
        ->assertSet('latestVersion', '4.0.0-beta.999')
        ->assertSet('isUpgradeAvailable', false);

    expect((bool) InstanceSettings::findOrFail(0)->new_version_available)->toBeFalse();
});

function upgradeComponentTestLoginToRootTeam(string $role, ?float $availableGb = null): void
{
    config(['constants.coolify.version' => '4.0.0-beta.998']);
    Cache::put('coolify:versions:all', ['coolify' => ['v4' => ['version' => '4.0.0-beta.999']]], 3600);
    InstanceSettings::forceCreate(['id' => 0, 'new_version_available' => true]);
    $rootTeam = Team::factory()->create(['id' => 0]);
    Server::forceCreate([
        'id' => 0,
        'name' => 'localhost',
        'ip' => '127.0.0.1',
        'user' => 'root',
        'team_id' => 0,
        'private_key_id' => 1,
    ]);

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    test()->mock(CoolifyUpgradeDiskSpace::class)
        ->shouldReceive('availableGb')
        ->andReturn($availableGb);
}

it('does not start the upgrade when the disk is almost full', function () {
    upgradeComponentTestLoginToRootTeam('admin', 3.2);

    Livewire::test(Upgrade::class)
        ->call('upgrade')
        ->assertReturned(['status' => 'low_disk_space', 'available_gb' => 3.2, 'required_gb' => 5])
        ->assertSet('updateInProgress', false);
});

it('starts the upgrade when the disk has enough free space', function () {
    upgradeComponentTestLoginToRootTeam('admin', 42.0);

    Livewire::test(Upgrade::class)
        ->call('upgrade')
        ->assertReturned(['status' => 'started'])
        ->assertSet('updateInProgress', true);
});

it('starts the upgrade on a low disk when the admin confirms the override', function () {
    upgradeComponentTestLoginToRootTeam('admin', 3.2);

    Livewire::test(Upgrade::class)
        ->call('upgrade', true)
        ->assertReturned(['status' => 'started'])
        ->assertSet('updateInProgress', true);
});

it('starts a docker cleanup on the localhost server for an instance admin', function () {
    Queue::fake();
    upgradeComponentTestLoginToRootTeam('admin', 3.2);

    Livewire::test(Upgrade::class)
        ->call('runDockerCleanup')
        ->assertDispatched('success');

    Queue::assertPushed(DockerCleanupJob::class, fn (DockerCleanupJob $job) => $job->server->id === 0 && $job->manualCleanup);
});

it('does not let a root team member check disk space, clean up docker, or upgrade', function () {
    Queue::fake();
    upgradeComponentTestLoginToRootTeam('member', 3.2);

    Livewire::test(Upgrade::class)
        ->call('checkDiskSpace')
        ->assertForbidden();

    Livewire::test(Upgrade::class)
        ->call('runDockerCleanup')
        ->call('upgrade', true)
        ->assertSet('updateInProgress', false);

    Queue::assertNotPushed(DockerCleanupJob::class);
});
