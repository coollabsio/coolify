<?php

use App\Livewire\Upgrade;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Services\MigrationFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    MigrationFailure::clear();
});

it('clears a stale migration failure marker and returns success when migrations are disabled', function () {
    config(['constants.migration.is_migration_enabled' => false]);
    MigrationFailure::record('a previous migration failure');
    expect(MigrationFailure::current())->not->toBeNull();

    $this->artisan('start:migration')->assertExitCode(0);

    expect(MigrationFailure::current())->toBeNull();
});

it('surfaces a recorded migration failure as an error through the upgrade status', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $team = Team::factory()->create(['id' => 0]);
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    MigrationFailure::record('SQLSTATE[HY000]: disk full');

    $component = new Upgrade;
    $component->currentVersion = '4.0.0';
    $component->latestVersion = '4.1.0';

    $status = $component->getUpgradeStatus();

    expect($status['status'])->toBe('error');
    expect($status['message'])
        ->toContain('Database migration failed')
        ->toContain('SQLSTATE[HY000]: disk full');
});

it('clears a stale migration failure marker when a new upgrade is started', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $team = Team::factory()->create(['id' => 0]);
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    MigrationFailure::record('a previous migration failure');
    expect(MigrationFailure::current())->not->toBeNull();

    $component = new Upgrade;
    $component->upgrade();

    expect(MigrationFailure::current())->toBeNull();
});

it('does not surface an upgrade error for the root team when no migration failure is recorded', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $team = Team::factory()->create(['id' => 0]);
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    MigrationFailure::clear();

    $component = new Upgrade;
    $component->currentVersion = '4.0.0';
    $component->latestVersion = '4.1.0';

    // With no marker and no server 0 / status file, the status is not the migration error.
    expect($component->getUpgradeStatus()['status'])->not->toBe('error');
});
