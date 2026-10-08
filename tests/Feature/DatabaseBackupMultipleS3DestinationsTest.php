<?php

use App\Events\BackupCreated;
use App\Jobs\DatabaseBackupJob;
use App\Models\DatabaseBackupS3Replica;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
});

function multiS3Storage(Team $team, string $name): S3Storage
{
    return S3Storage::create([
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => strtolower($name).'-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
    ]);
}

function multiS3Execution(ScheduledDatabaseBackup $backup, string $name, array $attributes = []): ScheduledDatabaseBackupExecution
{
    $execution = ScheduledDatabaseBackupExecution::create([
        'uuid' => $name,
        'database_name' => 'app',
        'filename' => "/backup/{$name}.dmp",
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        's3_uploaded' => true,
        'local_storage_deleted' => true,
        ...$attributes,
    ]);

    return $execution;
}

/**
 * Records the files that deleteBackupsS3() removes, keyed by bucket.
 */
function fakeMultiS3Deletes(): Collection
{
    $deleted = collect();
    Storage::shouldReceive('build')->andReturnUsing(function (array $config) use ($deleted) {
        $disk = Mockery::mock();
        $disk->shouldReceive('delete')->andReturnUsing(function (array $files) use ($config, $deleted) {
            $deleted->put($config['bucket'], [...$deleted->get($config['bucket'], []), ...$files]);

            return true;
        });

        return $disk;
    });

    return $deleted;
}

/**
 * Runs a backup with two S3 destinations, failing the upload to the storages named in $failingStorages.
 *
 * @return array{0: ScheduledDatabaseBackupExecution, 1: Collection<int, string>}
 */
function runMultiS3BackupJob(array $failingStorages): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $database = create_standalone_postgresql($environment->id, $destination, ['status' => 'running:healthy']);
    $first = multiS3Storage($team, 'First');
    $second = multiS3Storage($team, 'Second');
    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill([
        'enabled' => true,
        'save_s3' => true,
        'disable_local_backup' => true,
        's3_storage_id' => $first->id,
        'frequency' => 'daily',
        'databases_to_backup' => 'app',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
    ])->save();
    $backup->syncS3Storages([$first->id, $second->id]);
    $commands = collect();
    Process::fake(function ($process) use ($commands) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $commands->push($command);

        return Process::result(output: str_contains($command, 'du -b') ? '128' : '');
    });

    $job = Mockery::mock(DatabaseBackupJob::class.'[upload_to_s3]', [$backup->fresh()])->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('upload_to_s3')->andReturnUsing(function (S3Storage $storage) use ($failingStorages) {
        if (in_array($storage->name, $failingStorages, true)) {
            throw new RuntimeException('access denied');
        }
    });
    $job->handle();

    return [ScheduledDatabaseBackupExecution::query()->sole(), $commands];
}

it('applies S3 retention to each destination separately', function () {
    $team = Team::factory()->create();
    $first = multiS3Storage($team, 'First');
    $second = multiS3Storage($team, 'Second');
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        'disable_local_backup' => true,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
        'database_backup_retention_amount_s3' => 1,
    ]);
    $backup->syncS3Storages([$first->id, $second->id]);
    $old = multiS3Execution($backup, 'old');
    $new = multiS3Execution($backup, 'new', ['s3_uploaded' => false]);
    ScheduledDatabaseBackupExecution::whereKey($old->id)->update(['created_at' => now()->subDay()]);
    $old->s3Replicas()->create(['s3_storage_id' => $first->id, 's3_uploaded' => true]);
    $old->s3Replicas()->create(['s3_storage_id' => $second->id, 's3_uploaded' => true]);
    $new->s3Replicas()->create(['s3_storage_id' => $first->id, 's3_uploaded' => true]);
    $new->s3Replicas()->create(['s3_storage_id' => $second->id, 's3_uploaded' => false, 'message' => 'denied']);
    $deleted = fakeMultiS3Deletes();

    removeOldBackups($backup);

    expect($deleted->all())->toBe(['first-bucket' => ['/backup/old.dmp']])
        ->and($old->s3Replicas()->where('s3_storage_id', $first->id)->sole()->s3_storage_deleted)->toBeTrue()
        ->and($old->s3Replicas()->where('s3_storage_id', $second->id)->sole()->s3_storage_deleted)->toBeFalse()
        ->and($old->fresh()->s3_storage_deleted)->toBeFalse()
        ->and($backup->executions()->count())->toBe(2);
});

it('deletes the execution record when its last S3 copy expires', function () {
    $team = Team::factory()->create();
    $first = multiS3Storage($team, 'First');
    $second = multiS3Storage($team, 'Second');
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        'disable_local_backup' => true,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
        'database_backup_retention_amount_s3' => 1,
    ]);
    $backup->syncS3Storages([$first->id, $second->id]);
    $old = multiS3Execution($backup, 'old');
    $new = multiS3Execution($backup, 'new');
    ScheduledDatabaseBackupExecution::whereKey($old->id)->update(['created_at' => now()->subDay()]);
    foreach ([$old, $new] as $execution) {
        $execution->s3Replicas()->create(['s3_storage_id' => $first->id, 's3_uploaded' => true]);
        $execution->s3Replicas()->create(['s3_storage_id' => $second->id, 's3_uploaded' => true]);
    }
    $deleted = fakeMultiS3Deletes();

    removeOldBackups($backup);

    expect($deleted->all())->toBe(['first-bucket' => ['/backup/old.dmp'], 'second-bucket' => ['/backup/old.dmp']])
        ->and($old->fresh())->toBeNull()
        ->and(DatabaseBackupS3Replica::where('execution_id', $old->id)->exists())->toBeFalse()
        ->and($new->fresh())->not->toBeNull();
});

it('marks copies in a deleted storage as gone without calling S3', function () {
    $team = Team::factory()->create();
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        'disable_local_backup' => true,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
    ]);
    $execution = multiS3Execution($backup, 'orphaned');
    $execution->s3Replicas()->create(['s3_storage_id' => null, 's3_uploaded' => true]);
    Storage::shouldReceive('build')->never();

    removeOldBackups($backup);

    expect($execution->fresh())->toBeNull();
});

it('keeps an execution whose local file still exists after its S3 copies expire', function () {
    $team = Team::factory()->create();
    $storage = multiS3Storage($team, 'First');
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
    ]);
    $execution = multiS3Execution($backup, 'local-copy', ['local_storage_deleted' => false]);
    $execution->s3Replicas()->create(['s3_storage_id' => $storage->id, 's3_uploaded' => true, 's3_storage_deleted' => true]);

    removeOldBackups($backup);

    expect($execution->fresh())->not->toBeNull();
});

it('deletes the S3 copies of an execution from every destination that has one', function () {
    $team = Team::factory()->create();
    $first = multiS3Storage($team, 'First');
    $second = multiS3Storage($team, 'Second');
    $failed = multiS3Storage($team, 'Failed');
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
    ]);
    $execution = multiS3Execution($backup, 'manual', ['s3_uploaded' => false]);
    $execution->s3Replicas()->create(['s3_storage_id' => $first->id, 's3_uploaded' => true]);
    $execution->s3Replicas()->create(['s3_storage_id' => $second->id, 's3_uploaded' => true]);
    $execution->s3Replicas()->create(['s3_storage_id' => $failed->id, 's3_uploaded' => false]);
    $deleted = fakeMultiS3Deletes();

    $execution->deleteS3Copies();

    expect($deleted->keys()->sort()->values()->all())->toBe(['first-bucket', 'second-bucket'])
        ->and($execution->fresh()->s3_storage_deleted)->toBeTrue()
        ->and($execution->hasLiveS3Copies())->toBeFalse();
});

describe('backup job', function () {
    beforeEach(function () {
        Storage::fake('ssh-keys');
        Storage::fake('ssh-mux');
        Event::fake([BackupCreated::class]);
        Notification::fake();
        InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    });

    it('uploads to every destination and deletes the local file when all uploads succeed', function () {
        [$execution, $commands] = runMultiS3BackupJob([]);

        expect($execution->status)->toBe('success')
            ->and($execution->s3_uploaded)->toBeTrue()
            ->and($execution->local_storage_deleted)->toBeTrue()
            ->and($execution->s3Replicas()->pluck('s3_uploaded')->all())->toBe([true, true])
            ->and($commands->contains(fn (string $command) => str_contains($command, 'rm -f') && str_contains($command, $execution->filename)))->toBeTrue();
    });

    it('continues to later destinations and keeps the local file when a replica write fails', function (array $failingStorages) {
        $event = 'eloquent.creating: '.DatabaseBackupS3Replica::class;
        Event::listen($event, function (DatabaseBackupS3Replica $replica): void {
            if (S3Storage::find($replica->s3_storage_id)?->name === 'First') {
                throw new RuntimeException('replica persistence failed');
            }
        });

        try {
            [$execution, $commands] = runMultiS3BackupJob($failingStorages);
        } finally {
            Event::forget($event);
        }

        $replica = $execution->s3Replicas()->with('s3')->sole();
        expect($replica->s3->name)->toBe('Second')
            ->and($replica->s3_uploaded)->toBeTrue()
            ->and($execution->s3_storage_deleted)->toBeFalse()
            ->and($execution->s3_uploaded)->toBeFalse()
            ->and($execution->local_storage_deleted)->toBeFalse()
            ->and($execution->message)->toContain('First: replica persistence failed')
            ->and($commands->contains(fn (string $command) => str_contains($command, 'rm -f') && str_contains($command, $execution->filename)))->toBeFalse();

        if ($failingStorages !== []) {
            expect($execution->message)->toContain('First: access denied');
        }
    })->with([
        'successful upload' => [[]],
        'failed upload' => [['First']],
    ]);

    it('keeps the local file and names the failed destination when one upload fails', function () {
        [$execution, $commands] = runMultiS3BackupJob(['Second']);

        $replicas = $execution->s3Replicas()->with('s3')->get()->keyBy(fn ($replica) => $replica->s3->name);
        expect($execution->status)->toBe('success')
            ->and($execution->s3_uploaded)->toBeFalse()
            ->and($execution->local_storage_deleted)->toBeFalse()
            ->and($execution->message)->toContain('Second: access denied')
            ->and($replicas['First']->s3_uploaded)->toBeTrue()
            ->and($replicas['Second']->s3_uploaded)->toBeFalse()
            ->and($replicas['Second']->message)->toBe('access denied')
            ->and($commands->contains(fn (string $command) => str_contains($command, 'rm -f') && str_contains($command, $execution->filename)))->toBeFalse();
    });
});

it('deletes an execution from every S3 destination through the API', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    $sshKeys = Storage::fake('ssh-keys');
    $sshMux = Storage::fake('ssh-mux');
    Process::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $token = $user->createToken('test-token', ['*'])->plainTextToken;
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $database = create_standalone_postgresql($environment->id, $destination);
    $first = multiS3Storage($team, 'First');
    $second = multiS3Storage($team, 'Second');
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        'database_type' => $database->getMorphClass(),
        'database_id' => $database->id,
        'team_id' => $team->id,
    ]);
    $backup->syncS3Storages([$first->id, $second->id]);
    $execution = multiS3Execution($backup, 'api', ['local_storage_deleted' => false]);
    $execution->s3Replicas()->create(['s3_storage_id' => $first->id, 's3_uploaded' => true]);
    $execution->s3Replicas()->create(['s3_storage_id' => $second->id, 's3_uploaded' => true]);
    $deleted = fakeMultiS3Deletes();
    Storage::shouldReceive('disk')->with('ssh-keys')->andReturn($sshKeys);
    Storage::shouldReceive('disk')->with('ssh-mux')->andReturn($sshMux);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->deleteJson("/api/v1/databases/{$database->uuid}/backups/{$backup->uuid}/executions/{$execution->uuid}?delete_s3=true")
        ->assertOk();

    expect($deleted->all())->toBe(['first-bucket' => ['/backup/api.dmp'], 'second-bucket' => ['/backup/api.dmp']])
        ->and($execution->fresh())->toBeNull();
});

it('copies the destinations of the same team to a cloned schedule', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $first = multiS3Storage($team, 'First');
    $second = multiS3Storage($team, 'Second');
    $foreign = multiS3Storage($otherTeam, 'Foreign');
    $attributes = [
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
    ];
    $backup = ScheduledDatabaseBackup::create($attributes);
    $backup->syncS3Storages([$first->id, $second->id]);
    $backup->s3Storages()->attach($foreign->id);
    $copy = ScheduledDatabaseBackup::create($attributes);

    $backup->copyS3StoragesTo($copy);

    expect($copy->s3Storages()->pluck('s3_storages.id')->sort()->values()->all())->toBe([$first->id, $second->id]);
});

it('requires a live S3 copy for local retention only when local backups are disabled', function (bool $disabled, ?array $replica, bool $deleted) {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $database = create_standalone_postgresql($environment->id, $destination);
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        'disable_local_backup' => $disabled,
        'database_backup_retention_amount_locally' => 1,
        'database_type' => $database->getMorphClass(),
        'database_id' => $database->id,
        'team_id' => $team->id,
    ]);
    $older = multiS3Execution($backup, 'older', ['local_storage_deleted' => false, 'created_at' => now()->subDay()]);
    $newer = multiS3Execution($backup, 'newer', ['local_storage_deleted' => false]);
    if ($replica !== null) {
        $storage = multiS3Storage($team, 'Retention');
        foreach ([$older, $newer] as $execution) {
            $execution->s3Replicas()->create(['s3_storage_id' => $storage->id, ...$replica]);
        }
    }
    $commands = collect();
    Process::fake(function ($process) use ($commands) {
        $commands->push(is_array($process->command) ? implode(' ', $process->command) : $process->command);

        return Process::result();
    });

    removeOldBackups($backup);

    expect($commands->contains(fn (string $command): bool => str_contains($command, '/backup/older.dmp')))->toBe($deleted);
    if ($deleted && $replica === null) {
        $this->assertModelMissing($older);
    } else {
        expect($older->fresh()->local_storage_deleted)->toBe($deleted);
    }
    expect($newer->fresh()->local_storage_deleted)->toBeFalse();
})->with([
    'no replica' => [true, null, false],
    'failed upload' => [true, ['s3_uploaded' => false], false],
    'deleted replica' => [true, ['s3_uploaded' => true, 's3_storage_deleted' => true], false],
    'live replica' => [true, ['s3_uploaded' => true, 's3_storage_deleted' => false], true],
    'normal local retention' => [false, null, true],
]);
