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
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('empty component renders the actions slot', function () {
    $html = Blade::render(<<<'BLADE'
        <x-empty title="Backup is not configured" description="Needs a database." size="sm">
            <x-slot:actions>
                <button type="button">Configure backup</button>
            </x-slot:actions>
        </x-empty>
    BLADE);

    expect($html)
        ->toContain('Backup is not configured')
        ->toContain('Configure backup');
});

test('empty component renders the contents slot for backward compatibility', function () {
    $html = Blade::render(<<<'BLADE'
        <x-empty title="Metrics are disabled" size="sm">
            <x-slot:contents>
                <button type="button">Enable metrics</button>
            </x-slot:contents>
        </x-empty>
    BLADE);

    expect($html)
        ->toContain('Metrics are disabled')
        ->toContain('Enable metrics');
});

test('empty component renders only the contents slot when both action slots are provided', function () {
    $html = Blade::render(<<<'BLADE'
        <x-empty title="Conflict check" size="sm">
            <x-slot:actions>
                <button type="button">From actions</button>
            </x-slot:actions>
            <x-slot:contents>
                <button type="button">From contents</button>
            </x-slot:contents>
        </x-empty>
    BLADE);

    expect($html)
        ->toContain('From contents')
        ->not->toContain('From actions');
});

test('instance backup settings show a configure button when backup is not set up', function () {
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
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(SettingsBackup::class)
        ->assertOk()
        ->assertSee('Backup is not configured')
        ->assertSee('Configure backup')
        ->assertSeeHtml('wire:click="addCoolifyDatabase"');
});

function settingsBackupInstanceDatabaseWithoutSchedule(): StandalonePostgresql
{
    Server::flushIdentityMap();
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

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    test()->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    return $database;
}

test('instance backup settings render when the instance database has no backup schedule', function () {
    settingsBackupInstanceDatabaseWithoutSchedule();

    Livewire::test(SettingsBackup::class)
        ->assertOk()
        ->assertSet('executions', [])
        ->assertSee('Backup is not configured')
        ->assertSee('Configure backup');
});

test('configure backup creates only the missing schedule for an existing instance database', function () {
    $database = settingsBackupInstanceDatabaseWithoutSchedule();
    $otherTeam = Team::factory()->create();
    auth()->user()->teams()->attach($otherTeam->id, ['role' => 'owner']);
    session(['currentTeam' => ['id' => $otherTeam->id]]);
    Process::fake();

    Livewire::test(SettingsBackup::class)
        ->call('addCoolifyDatabase')
        ->assertHasNoErrors()
        ->assertSee('Instance database');

    Process::assertNothingRan();
    $backup = $database->scheduledBackups()->sole();

    expect(StandalonePostgresql::query()->where('name', 'coolify-db')->count())->toBe(1)
        ->and($backup->id)->not->toBe(0)
        ->and($backup->team_id)->toBe(0)
        ->and($backup->enabled)->toBeTruthy()
        ->and($backup->frequency)->toBe('0 0 * * *')
        ->and(ScheduledDatabaseBackup::query()->count())->toBe(1);
});
