<?php

use App\Livewire\Upgrade;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

it('uses sidebar state css instead of nested alpine state for upgrade labels', function () {
    $upgradeView = file_get_contents(resource_path('views/livewire/upgrade.blade.php'));
    $utilitiesCss = file_get_contents(resource_path('css/utilities.css'));

    expect($upgradeView)
        ->toContain('class="text-left menu-item-label sidebar-collapsed-label"')
        ->toContain('>In progress</span>')
        ->toContain('>Upgrade</span>')
        ->not->toContain(':class="collapsed && \'lg:hidden\'"')
        ->and($utilitiesCss)
        ->toContain('.sidebar-collapsed .sidebar-collapsed-label')
        ->toContain('display: none;');
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
