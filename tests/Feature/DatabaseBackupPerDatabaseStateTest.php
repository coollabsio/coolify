<?php

use App\Events\BackupCreated;
use App\Jobs\DatabaseBackupJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Event::fake([BackupCreated::class]);
    Notification::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
});

afterEach(function () {
    ScheduledDatabaseBackupExecution::flushEventListeners();
});

it('keeps the successful execution of an earlier database when creating the next execution fails', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $database = create_standalone_postgresql($environment->id, $destination, ['status' => 'running:healthy']);
    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => 'daily',
        'databases_to_backup' => 'first,second',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
    ])->save();
    $commands = collect();
    Process::fake(function ($process) use ($commands) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $commands->push($command);

        return Process::result(output: str_contains($command, 'du -b') ? '128' : '');
    });
    ScheduledDatabaseBackupExecution::creating(function (ScheduledDatabaseBackupExecution $execution) {
        if ($execution->database_name === 'second') {
            throw new RuntimeException('database is unavailable');
        }
    });

    (new DatabaseBackupJob($backup->fresh()))->handle();

    $first = ScheduledDatabaseBackupExecution::query()->sole();
    expect($first->database_name)->toBe('first')
        ->and($first->status)->toBe('success')
        ->and($first->filename)->not->toBeNull()
        ->and($commands->contains(fn (string $command) => str_contains($command, 'rm ') && str_contains($command, $first->filename)))->toBeFalse();
});

it('finishes the execution of every database in a run with several databases', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $database = create_standalone_postgresql($environment->id, $destination, ['status' => 'running:healthy']);
    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => 'daily',
        'databases_to_backup' => 'first,second,third',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
    ])->save();
    Process::fake(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        if (str_contains($command, 'pg_dump') && str_contains($command, 'second')) {
            return Process::result(errorOutput: 'pg_dump: database "second" does not exist', exitCode: 1);
        }

        return Process::result(output: str_contains($command, 'du -b') ? '128' : '');
    });

    (new DatabaseBackupJob($backup->fresh()))->handle();

    $executions = ScheduledDatabaseBackupExecution::query()->orderBy('id')->get();
    expect($executions->map->only(['database_name', 'status'])->all())->toBe([
        ['database_name' => 'first', 'status' => 'success'],
        ['database_name' => 'second', 'status' => 'failed'],
        ['database_name' => 'third', 'status' => 'success'],
    ])
        ->and($executions->whereNull('finished_at')->pluck('database_name')->all())->toBe([]);
});
