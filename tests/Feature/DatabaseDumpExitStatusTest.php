<?php

use App\Jobs\DatabaseBackupJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneSqlite;
use App\Models\Team;
use App\Support\DatabaseImport\DatabaseImportCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->workDirectory = sys_get_temp_dir().'/coolify-dump-exit-status-'.uniqid();
    mkdir($this->workDirectory.'/bin', 0700, true);
});

afterEach(function () {
    Process::run(['rm', '-rf', $this->workDirectory]);
});

it('fails a compressed dump when the dump or the compressor fails', function (string $source, string $sink, bool $succeeds, ?string $content) {
    $file = $this->workDirectory.'/backup.gz';

    $result = Process::run(['sh', '-c', pipeToFileKeepingExitStatus($source, $sink, escapeshellarg($file))]);

    expect($result->successful())->toBe($succeeds);
    if ($content !== null) {
        expect(gzdecode(file_get_contents($file)))->toBe($content);
    }
})->with([
    'dump fails after partial output' => ['sh -c "echo partial; exit 1"', 'gzip', false, null],
    'dump cannot open the database' => ['sh -c "exit 1"', 'gzip', false, null],
    'compressor fails' => ['echo data', 'false', false, null],
    'dump and compressor succeed' => ['echo data', 'gzip', true, "data\n"],
]);

it('restores a SQLite backup only when it is a SQLite database file', function (string $backupContent, bool $restores) {
    $backup = $this->workDirectory.'/restore';
    file_put_contents($backup, gzencode($backupContent));
    $marker = $this->workDirectory.'/sqlite3-called';
    file_put_contents($this->workDirectory.'/bin/sqlite3', "#!/bin/sh\ntouch ".escapeshellarg($marker)."\n");
    chmod($this->workDirectory.'/bin/sqlite3', 0755);
    $database = Mockery::mock(StandaloneSqlite::class);
    $database->shouldReceive('getMorphClass')->andReturn(StandaloneSqlite::class);
    $database->shouldReceive('databaseFilePath')->andReturn($this->workDirectory.'/app.db');

    $script = app(DatabaseImportCommandBuilder::class)->buildRestoreCommand($database, $backup, false);
    $result = Process::env(['PATH' => $this->workDirectory.'/bin:'.getenv('PATH')])->run(['sh', '-c', $script]);

    expect($result->successful())->toBe($restores)
        ->and(file_exists($marker))->toBe($restores)
        ->and(file_exists($backup.'.db'))->toBeFalse();
    if (! $restores) {
        expect($result->errorOutput())->toContain('The backup is not a SQLite database. Nothing was changed.');
    }
})->with([
    'empty backup from a failed dump' => ['', false],
    'SQL text instead of a database file' => ['CREATE TABLE t (x TEXT);', false],
    'SQLite database file' => ["SQLite format 3\0".str_repeat("\0", 84), true],
]);

it('records a failed SQLite dump as failed and removes the partial backup file', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Notification::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = create_standalone_sqlite($environment->id, StandaloneDocker::where('server_id', $server->id)->firstOrFail(), [
        'sqlite_databases' => 'app.db',
        'status' => 'running:healthy',
    ]);
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => StandaloneSqlite::class,
        'database_id' => $database->id,
        'team_id' => $team->id,
        'databases_to_backup' => 'app.db',
    ]);
    $commands = collect();
    Process::fake(function ($process) use ($commands) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $commands->push($command);

        return str_contains($command, 'VACUUM INTO')
            ? Process::result(errorOutput: 'Error: unable to open database "/var/lib/sqlite/app.db"', exitCode: 1)
            : Process::result(output: '');
    });

    try {
        (new DatabaseBackupJob($backup))->handle();
    } catch (Throwable) {
        // The job reports the failure after it records the execution.
    }

    $execution = $backup->executions()->latest('id')->firstOrFail();
    expect($execution->status)->toBe('failed')
        ->and($execution->filename)->toBeNull()
        ->and($commands->implode("\n"))->toMatch("/rm -f '[^']*sqlite-backup-app\\.db-\\d+-[a-z0-9]+\\.gz'/");
});
