<?php

use App\Livewire\SettingsBackup;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
});

function settingsBackupBeforeUpdateInstanceAdmin(): User
{
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => $rootTeam->id,
        'ip' => '127.0.0.1',
    ]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail()->forceFill(['id' => 0])->save();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $database = new StandalonePostgresql;
    $database->forceFill([
        'id' => 0,
        'name' => 'coolify-db',
        'description' => 'Coolify database',
        'postgres_user' => 'coolify',
        'postgres_password' => 'password',
        'postgres_db' => 'coolify',
        'status' => 'running',
        'destination_type' => StandaloneDocker::class,
        'destination_id' => 0,
    ]);
    $database->save();
    ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'database_id' => $database->id,
        'database_type' => StandalonePostgresql::class,
        'team_id' => 0,
    ]);

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    test()->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    return $user;
}

test('instance admin can turn the backup before update off on the instance backup page', function () {
    settingsBackupBeforeUpdateInstanceAdmin();
    Process::fake();

    Livewire::test(SettingsBackup::class)
        ->assertSee('Backup before update')
        ->assertSet('is_backup_before_update_enabled', true)
        ->set('is_backup_before_update_enabled', false)
        ->call('instantSave')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    Process::assertNothingRan();
    expect(InstanceSettings::find(0)->is_backup_before_update_enabled)->toBeFalse();
});

test('a user who is no longer an instance admin cannot change the backup before update', function () {
    $user = settingsBackupBeforeUpdateInstanceAdmin();

    $component = Livewire::test(SettingsBackup::class);
    Team::find(0)->members()->updateExistingPivot($user->id, ['role' => 'member']);
    $user->refresh();

    $component->set('is_backup_before_update_enabled', false)
        ->call('instantSave')
        ->assertDispatched('error');

    expect(InstanceSettings::find(0)->is_backup_before_update_enabled)->toBeTrue();
});

test('an admin of another team cannot open the instance backup page', function () {
    settingsBackupBeforeUpdateInstanceAdmin();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => $team->id]]);

    Livewire::test(SettingsBackup::class)
        ->assertRedirect(route('dashboard'));
});
