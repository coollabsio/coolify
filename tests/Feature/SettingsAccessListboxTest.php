<?php

use App\Livewire\Settings\Advanced;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Access settings must always use the shared listbox control — not conditional
 * custom Enable/Disable cards — so the Access section matches DNS/API/etc.
 */
test('settings advanced access section always uses listboxes', function () {
    $path = resource_path('views/livewire/settings/advanced.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('id="is_registration_enabled"')
        ->toContain('id="disable_two_step_confirmation"')
        ->toContain('onChange="instantSave"')
        ->not->toContain('toggleRegistration')
        ->not->toContain('toggleTwoStepConfirmation')
        ->not->toContain('Only administrators can create accounts.')
        ->not->toContain('Two-step confirmations enabled');
});

test('instance admin can toggle registration via listbox instantSave', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    $settings = InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => false,
        'disable_two_step_confirmation' => false,
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Advanced::class)
        ->assertSet('is_registration_enabled', false)
        ->assertSee('Registration')
        ->assertSee('Destructive action confirmation')
        ->assertDontSee('Only administrators can create accounts.')
        ->set('is_registration_enabled', true)
        ->call('instantSave')
        ->assertDispatched('success');

    expect((bool) $settings->fresh()->is_registration_enabled)->toBeTrue();
});

test('instance admin can toggle two-step confirmation via listbox instantSave', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    $settings = InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => true,
        'disable_two_step_confirmation' => false,
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Advanced::class)
        ->assertSet('disable_two_step_confirmation', false)
        ->set('disable_two_step_confirmation', true)
        ->call('instantSave')
        ->assertDispatched('success');

    expect((bool) $settings->fresh()->disable_two_step_confirmation)->toBeTrue();
});

test('open API allowlist warning is hidden when API access is disabled', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_api_enabled' => false,
        'allowed_ips' => null,
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Advanced::class)
        ->assertSet('is_api_enabled', false)
        ->assertDontSee('API access is open to every source');
});

test('open API allowlist warning is shown when API access is enabled without allowlist', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_api_enabled' => true,
        'allowed_ips' => null,
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Advanced::class)
        ->assertSet('is_api_enabled', true)
        ->assertSee('API access is open to every source');
});
