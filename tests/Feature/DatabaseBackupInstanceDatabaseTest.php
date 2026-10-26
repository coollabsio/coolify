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

it('backs up a user database named coolify-db as an ordinary database', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $database = create_standalone_postgresql($environment->id, $destination, [
        'name' => 'coolify-db',
        'postgres_db' => 'appdb',
        'status' => 'running:healthy',
    ]);
    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => 'daily',
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

    (new DatabaseBackupJob($backup->fresh()))->handle();

    $execution = ScheduledDatabaseBackupExecution::query()->sole();
    $dumpCommand = $commands->first(fn (string $command) => str_contains($command, 'pg_dump'));
    expect($database->isInstanceDatabase())->toBeFalse()
        ->and($execution->database_name)->toBe('appdb')
        ->and($execution->filename)->toContain('/databases/')
        ->and($execution->filename)->not->toContain('/coolify/coolify-db-')
        ->and($dumpCommand)->toContain($database->uuid)
        ->and($dumpCommand)->not->toContain('exec coolify-db ');
    expect($server->databases()->pluck('id'))->toContain($database->id);
});
