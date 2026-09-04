<?php

use App\Livewire\SwitchTeam;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->currentTeam = $this->user->teams()->first();
    $this->otherTeam = Team::factory()->create();
    $this->otherTeam->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->currentTeam]);
});

test('switching teams keeps the current page URL', function () {
    $currentUrl = route('security.api-tokens', ['page' => 2]);

    Livewire::test(SwitchTeam::class)
        ->call('switch_to', $this->otherTeam->id, $currentUrl)
        ->assertRedirect($currentUrl);

    expect(session('currentTeam')->is($this->otherTeam))->toBeTrue();
});

test('instance admin can switch to a team they do not belong to', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $instanceAdmin = User::factory()->create();
    $rootTeam->members()->attach($instanceAdmin->id, ['role' => 'admin']);

    $targetTeam = Team::factory()->create();

    $this->actingAs($instanceAdmin);
    session(['currentTeam' => $rootTeam]);

    Livewire::test(SwitchTeam::class)
        ->call('switch_to', $targetTeam->id, '/')
        ->assertRedirect('/');

    expect(session('currentTeam')->is($targetTeam))->toBeTrue()
        ->and(auth()->id())->toBe($instanceAdmin->id);
});

test('regular user cannot switch to a team they do not belong to', function () {
    $targetTeam = Team::factory()->create();

    expect($this->user->teams()->whereKey($targetTeam->id)->exists())->toBeFalse();

    Livewire::test(SwitchTeam::class)
        ->call('switch_to', $targetTeam->id, route('dashboard'));

    expect(session('currentTeam')->is($this->currentTeam))->toBeTrue();
});

test('instance admin team switcher shows teams they do not belong to', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $instanceAdmin = User::factory()->create();
    $rootTeam->members()->attach($instanceAdmin->id, ['role' => 'admin']);

    $targetTeam = Team::factory()->create([
        'name' => 'Instance Admin Target Team',
    ]);

    $this->actingAs($instanceAdmin);
    session(['currentTeam' => $rootTeam]);

    expect($instanceAdmin->teams()->whereKey($targetTeam->id)->exists())->toBeFalse();

    Livewire::test(SwitchTeam::class)
        ->assertSee('Instance Admin Target Team');
});

test('instance admin currentTeam resolves a team they do not belong to', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $instanceAdmin = User::factory()->create();
    $rootTeam->members()->attach($instanceAdmin->id, ['role' => 'admin']);

    $targetTeam = Team::factory()->create();

    $this->actingAs($instanceAdmin);
    session(['currentTeam' => $targetTeam]);

    expect($instanceAdmin->currentTeam()?->is($targetTeam))->toBeTrue();
});

test('regular user currentTeam rejects a team they do not belong to', function () {
    $targetTeam = Team::factory()->create();

    session(['currentTeam' => $targetTeam]);

    expect($this->user->currentTeam())->toBeNull()
        ->and(session('currentTeam'))->toBeNull();
});
