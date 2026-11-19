<?php

use App\Livewire\SettingsBackup;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $rootTeam = Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => 0, 'ip' => '127.0.0.1']);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->user = User::factory()->create();
    $rootTeam->members()->attach($this->user->id, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => 0]]);
});

test('instance admin can turn the backup before update off', function () {
    Livewire::test(SettingsBackup::class)
        ->assertSet('is_backup_before_update_enabled', true)
        ->set('is_backup_before_update_enabled', false)
        ->call('instantSave')
        ->assertDispatched('success');

    expect(InstanceSettings::find(0)->is_backup_before_update_enabled)->toBeFalse();
});

test('team member cannot change the backup before update', function () {
    $component = Livewire::test(SettingsBackup::class);
    Team::find(0)->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->user->refresh();

    $component->set('is_backup_before_update_enabled', false)
        ->call('instantSave')
        ->assertDispatched('error', 'This action is unauthorized.');

    expect(InstanceSettings::find(0)->is_backup_before_update_enabled)->toBeTrue();
});
