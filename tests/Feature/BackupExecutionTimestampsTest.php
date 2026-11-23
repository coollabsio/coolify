<?php

use App\Livewire\Project\Database\BackupExecutions as DatabaseBackupExecutions;
use App\Livewire\Project\Service\BackupExecutions as ServiceBackupExecutions;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['server_timezone' => 'Europe/Budapest']);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->service = Service::factory()->create([
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
    ]);
    $database = ServiceDatabase::create([
        'service_id' => $this->service->id,
        'name' => 'timestamps-db',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);
    $this->backup = ScheduledDatabaseBackup::create([
        'team_id' => $team->id,
        'frequency' => '0 0 * * *',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
    ]);

    $this->travelTo(Carbon::parse('2026-10-16 14:00:00', 'UTC'));
    ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $this->backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/timestamps.dmp',
        'finished_at' => Carbon::parse('2026-10-16 14:30:05', 'UTC'),
    ]);
    $this->travelTo(Carbon::parse('2026-10-16 18:00:00', 'UTC'));

    $this->actingAs($user);
    session(['currentTeam' => $team]);
});

it('shows the exact finish time of database backup executions in the server timezone', function () {
    Livewire::test(DatabaseBackupExecutions::class, ['backup' => $this->backup])
        ->call('refreshBackupExecutions')
        ->assertSee('2026-10-16 16:30:05 CEST')
        ->assertSee('Started: 2026-10-16 16:00:00 CEST', false)
        ->assertSee('3 hours ago');
});

it('shows the exact start time of service backup executions in the server timezone', function () {
    Livewire::test(ServiceBackupExecutions::class, ['service' => $this->service])
        ->assertSee('2026-10-16 16:00:00 CEST')
        ->assertSee('4 hours ago');
});
