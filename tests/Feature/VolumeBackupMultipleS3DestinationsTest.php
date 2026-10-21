<?php

use App\Actions\Shared\DeleteScheduledVolumeBackup;
use App\Jobs\VolumeBackupJob;
use App\Jobs\VolumeBackupRecoveryJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Notification::fake();
    config(['broadcasting.default' => 'null']);
    defined('CURLOPT_RESOLVE') || define('CURLOPT_RESOLVE', 10203);
});

/**
 * @return array{0: ScheduledVolumeBackup, 1: S3Storage, 2: S3Storage, 3: Server}
 */
function createMultiDestinationVolumeBackup(array $backupAttributes = []): array
{
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.10',
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => 'app-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    $storages = collect(['first', 'second'])->map(fn (string $name): S3Storage => S3Storage::create([
        'name' => "{$name} storage",
        'region' => 'us-east-1',
        'key' => "{$name}-key",
        'secret' => 'secret',
        'bucket' => "{$name}-bucket",
        'endpoint' => 'https://s3.amazonaws.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]));
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'save_s3' => true,
        ...$backupAttributes,
    ]);
    $backup->syncS3Storages($storages->pluck('id')->all());

    return [$backup->fresh(), $storages[0], $storages[1], $server];
}

function fakeMultiDestinationS3Disks(): void
{
    $sshDisk = Storage::fake('ssh-keys');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('files')->zeroOrMoreTimes()->andReturn([]);
    $disk->shouldReceive('delete')->zeroOrMoreTimes()->andReturnTrue();
    Storage::shouldReceive('disk')->with('ssh-keys')->andReturn($sshDisk);
    Storage::shouldReceive('build')->zeroOrMoreTimes()->andReturn($disk);
}

it('archives locally and uploads to every destination instead of streaming', function () {
    [$backup, $first, $second] = createMultiDestinationVolumeBackup(['disable_local_backup' => true]);
    fakeMultiDestinationS3Disks();
    Process::fake([
        '*du -b*' => '128',
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    $execution = ScheduledVolumeBackupExecution::query()->sole();
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'mc pipe'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'tar -I') && str_contains($process->command, ' > '));
    Process::assertRan(fn ($process) => str_contains($process->command, 'mc cp') && str_contains($process->command, 'temporary/first-bucket/'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'mc cp') && str_contains($process->command, 'temporary/second-bucket/'));
    Process::assertRan(fn ($process) => preg_match("/rm -f '[^']+\\.tar\\.gz'/", $process->command) === 1);
    expect($execution->status)->toBe('success')
        ->and($execution->s3_storage_id)->toBe($first->id)
        ->and($execution->s3_uploaded)->toBeTrue()
        ->and($execution->local_storage_deleted)->toBeTrue()
        ->and($execution->s3Replicas()->orderBy('s3_storage_id')->get()->map->only(['s3_storage_id', 's3_uploaded'])->all())->toBe([
            ['s3_storage_id' => $first->id, 's3_uploaded' => true],
            ['s3_storage_id' => $second->id, 's3_uploaded' => true],
        ]);
});

it('keeps the local archive and cleans the partial upload when one destination fails', function () {
    [$backup, $first, $second] = createMultiDestinationVolumeBackup(['disable_local_backup' => true]);
    fakeMultiDestinationS3Disks();
    Process::fake([
        '*du -b*' => '128',
        '*temporary/second-bucket/*' => Process::result(errorOutput: 'access denied', exitCode: 1),
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    $execution = ScheduledVolumeBackupExecution::query()->sole();
    $firstReplica = $execution->s3Replicas()->where('s3_storage_id', $first->id)->sole();
    $secondReplica = $execution->s3Replicas()->where('s3_storage_id', $second->id)->sole();
    Process::assertNotRan(fn ($process) => preg_match("/rm -f '[^']+\\.tar\\.gz'/", $process->command) === 1);
    expect($execution->status)->toBe('success')
        ->and($execution->message)->toContain('S3 upload failed: second storage:')
        ->and($execution->s3_uploaded)->toBeFalse()
        ->and($execution->s3_cleanup_pending)->toBeFalse()
        ->and($execution->local_storage_deleted)->toBeFalse()
        ->and($firstReplica->s3_uploaded)->toBeTrue()
        ->and($firstReplica->s3_storage_deleted)->toBeFalse()
        ->and($secondReplica->s3_uploaded)->toBeFalse()
        ->and($secondReplica->s3_storage_deleted)->toBeTrue()
        ->and($secondReplica->message)->not->toBeEmpty();
});

it('applies S3 retention to each destination separately', function () {
    [$backup, $first, $second, $server] = createMultiDestinationVolumeBackup([
        'retention_amount_locally' => 0,
        'retention_days_locally' => 0,
        'retention_max_storage_locally' => 0,
        'retention_amount_s3' => 1,
        'retention_days_s3' => 0,
        'retention_max_storage_s3' => 0,
    ]);
    $createExecution = function (string $name, int $daysAgo, array $uploadedTo) use ($backup, $first, $second): ScheduledVolumeBackupExecution {
        $execution = ScheduledVolumeBackupExecution::create([
            'scheduled_volume_backup_id' => $backup->id,
            's3_storage_id' => $first->id,
            'status' => 'success',
            'filename' => "/data/coolify/backups/volumes/test/{$name}.tar.gz",
            'local_storage_deleted' => true,
            'created_at' => now()->subDays($daysAgo),
        ]);
        foreach ([$first, $second] as $storage) {
            $execution->s3Replicas()->create([
                's3_storage_id' => $storage->id,
                's3_uploaded' => in_array($storage->id, $uploadedTo, true),
            ]);
        }
        $execution->refreshS3Summary();

        return $execution;
    };
    $oldest = $createExecution('oldest', 3, [$first->id]);
    $older = $createExecution('older', 2, [$first->id, $second->id]);
    $latest = $createExecution('latest', 1, [$first->id]);
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')
        ->once()
        ->with(['/data/coolify/backups/volumes/test/older.tar.gz', '/data/coolify/backups/volumes/test/oldest.tar.gz'])
        ->andReturnTrue();
    Storage::shouldReceive('build')
        ->once()
        ->with(Mockery::on(fn (array $config): bool => $config['key'] === 'first-key'))
        ->andReturn($disk);
    $job = new VolumeBackupJob($backup);

    (new ReflectionClass($job))->getMethod('removeExpiredBackups')->invoke($job, $server);

    expect($oldest->fresh())->toBeNull()
        ->and($older->fresh())->not->toBeNull()
        ->and($older->s3Replicas()->where('s3_storage_id', $first->id)->sole()->s3_storage_deleted)->toBeTrue()
        ->and($older->s3Replicas()->where('s3_storage_id', $second->id)->sole()->s3_storage_deleted)->toBeFalse()
        ->and($older->fresh()->s3_storage_deleted)->toBeFalse()
        ->and($latest->s3Replicas()->where('s3_storage_id', $first->id)->sole()->s3_storage_deleted)->toBeFalse();
});

it('continues retention and execution cleanup when a replica storage is missing', function () {
    $this->freezeTime();
    [$backup, , $second, $server] = createMultiDestinationVolumeBackup([
        'retention_amount_locally' => 0,
        'retention_days_locally' => 0,
        'retention_max_storage_locally' => 0,
        'retention_amount_s3' => 0,
        'retention_days_s3' => 7,
        'retention_max_storage_s3' => 0,
    ]);
    $executions = collect([false, true])->map(function (bool $localDeleted) use ($backup, $second): ScheduledVolumeBackupExecution {
        $execution = $backup->executions()->create([
            'status' => 'success',
            'filename' => '/data/coolify/backups/volumes/test/'.($localDeleted ? 'deleted' : 'local').'.tar.gz',
            'local_storage_deleted' => $localDeleted,
        ]);
        $execution->forceFill(['created_at' => now()->subDays(10)])->save();
        $execution->s3Replicas()->create(['s3_storage_id' => null, 's3_uploaded' => true]);
        $execution->s3Replicas()->create(['s3_storage_id' => $second->id, 's3_uploaded' => true]);
        $execution->refreshS3Summary();

        return $execution;
    });
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')->once()->with(Mockery::on(
        fn (array $filenames): bool => count($filenames) === 2
            && in_array($executions[0]->filename, $filenames, true)
            && in_array($executions[1]->filename, $filenames, true),
    ))->andReturnTrue();
    Storage::shouldReceive('build')->once()
        ->with(Mockery::on(fn (array $config): bool => $config['key'] === 'second-key'))
        ->andReturn($disk);
    $job = new VolumeBackupJob($backup);

    (new ReflectionClass($job))->getMethod('removeExpiredBackups')->invoke($job, $server);

    expect($executions[0]->fresh()->s3_storage_deleted)->toBeTrue()
        ->and($executions[0]->s3Replicas()->where('s3_storage_deleted', false)->count())->toBe(0);
    $this->assertModelMissing($executions[1]);
});

it('cleans an interrupted upload from every destination whose copy did not finish', function () {
    [$backup, $first, $second] = createMultiDestinationVolumeBackup();
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $first->id,
        'status' => 'failed',
        'filename' => '/data/coolify/backups/volumes/test/interrupted.tar.gz',
        's3_cleanup_pending' => true,
    ]);
    $uploaded = $execution->s3Replicas()->create(['s3_storage_id' => $first->id, 's3_uploaded' => true]);
    $interrupted = $execution->s3Replicas()->create(['s3_storage_id' => $second->id]);
    $withoutStorage = $execution->s3Replicas()->create(['s3_storage_id' => null, 's3_uploaded' => false]);
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')->once()->with(['/data/coolify/backups/volumes/test/interrupted.tar.gz'])->andReturnTrue();
    Storage::shouldReceive('build')
        ->once()
        ->with(Mockery::on(fn (array $config): bool => $config['key'] === 'second-key'))
        ->andReturn($disk);

    VolumeBackupRecoveryJob::cleanupS3Upload($execution);

    expect($execution->fresh()->s3_cleanup_pending)->toBeFalse()
        ->and($uploaded->fresh()->s3_storage_deleted)->toBeFalse()
        ->and($interrupted->fresh()->s3_storage_deleted)->toBeTrue()
        ->and($withoutStorage->fresh()->s3_storage_deleted)->toBeTrue();
});

it('keeps the cleanup pending when one destination cannot delete the partial upload', function () {
    [$backup, $first, $second] = createMultiDestinationVolumeBackup();
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $first->id,
        'status' => 'failed',
        'filename' => '/data/coolify/backups/volumes/test/interrupted.tar.gz',
        's3_cleanup_pending' => true,
    ]);
    $failing = $execution->s3Replicas()->create(['s3_storage_id' => $first->id, 's3_uploaded' => false]);
    $cleaned = $execution->s3Replicas()->create(['s3_storage_id' => $second->id, 's3_uploaded' => false]);
    $failingDisk = Mockery::mock();
    $failingDisk->shouldReceive('delete')->once()->andReturnFalse();
    $cleanedDisk = Mockery::mock();
    $cleanedDisk->shouldReceive('delete')->once()->andReturnTrue();
    Storage::shouldReceive('build')->with(Mockery::on(fn (array $config): bool => $config['key'] === 'first-key'))->andReturn($failingDisk);
    Storage::shouldReceive('build')->with(Mockery::on(fn (array $config): bool => $config['key'] === 'second-key'))->andReturn($cleanedDisk);

    expect(fn () => VolumeBackupRecoveryJob::cleanupS3Upload($execution))->toThrow(RuntimeException::class);

    expect($execution->fresh()->s3_cleanup_pending)->toBeTrue()
        ->and($failing->fresh()->s3_storage_deleted)->toBeFalse()
        ->and($cleaned->fresh()->s3_storage_deleted)->toBeTrue();
});

it('moves the primary destination to a remaining storage when the primary storage is deleted', function () {
    [$backup, $first, $second] = createMultiDestinationVolumeBackup();
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $first->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/archive.tar.gz',
    ]);
    $execution->s3Replicas()->create(['s3_storage_id' => $first->id, 's3_uploaded' => true]);
    $execution->s3Replicas()->create(['s3_storage_id' => $second->id, 's3_uploaded' => true]);
    $execution->refreshS3Summary();

    expect($backup->s3_storage_id)->toBe($first->id);

    $first->delete();

    $backup->refresh();
    expect($backup->save_s3)->toBeTrue()
        ->and($backup->s3_storage_id)->toBe($second->id)
        ->and($backup->s3Storages()->pluck('s3_storages.id')->all())->toBe([$second->id])
        ->and($execution->fresh()->s3_storage_deleted)->toBeFalse()
        ->and($execution->hasLiveS3Copies())->toBeTrue();
});

it('preserves the schedule and uploaded replica when its S3 storage is unavailable', function () {
    [$backup] = createMultiDestinationVolumeBackup();
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/archive.tar.gz',
        'local_storage_deleted' => true,
    ]);
    $replica = $execution->s3Replicas()->create([
        's3_storage_id' => null,
        's3_uploaded' => true,
        's3_storage_deleted' => false,
    ]);
    $execution->refreshS3Summary();

    expect(fn () => (new DeleteScheduledVolumeBackup)->handle($backup, deleteLocalArchives: false))
        ->toThrow(RuntimeException::class, 'The S3 storage used by an existing backup is unavailable.');

    expect($backup->fresh())->not->toBeNull();
    expect($replica->fresh()->s3_storage_deleted)->toBeFalse();
    expect($execution->fresh()->s3_storage_deleted)->toBeFalse();
    expect($execution->hasLiveS3Copies())->toBeTrue();
});

it('rolls back all backup detachment changes when an execution update fails', function () {
    [$backup, $storage] = createMultiDestinationVolumeBackup();
    $backup->syncS3Storages([$storage->id]);
    $databaseBackup = ScheduledDatabaseBackup::create([
        'frequency' => 'daily',
        'save_s3' => true,
        'database_type' => 'App\\Models\\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $storage->team_id,
    ]);
    $databaseBackup->syncS3Storages([$storage->id]);
    $databaseExecution = $databaseBackup->executions()->create([
        'uuid' => 'rollback-database',
        'database_name' => 'app',
        'status' => 'success',
        's3_uploaded' => true,
    ]);
    $databaseReplica = $databaseExecution->s3Replicas()->create([
        's3_storage_id' => $storage->id,
        's3_uploaded' => true,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $storage->id,
        'status' => 'failed',
        's3_cleanup_pending' => true,
    ]);
    $replica = $execution->s3Replicas()->create([
        's3_storage_id' => $storage->id,
        's3_uploaded' => false,
    ]);
    Event::listen('eloquent.updating: '.ScheduledVolumeBackupExecution::class, function (): void {
        throw new RuntimeException('Execution update failed');
    });

    expect(fn () => $storage->delete())->toThrow(RuntimeException::class, 'Execution update failed');

    $this->assertModelExists($storage);
    foreach ([$databaseBackup, $backup] as $schedule) {
        expect($schedule->fresh()->s3_storage_id)->toBe($storage->id)
            ->and((bool) $schedule->fresh()->save_s3)->toBeTrue()
            ->and($schedule->s3Storages()->pluck('s3_storages.id')->all())->toBe([$storage->id]);
    }
    expect($databaseReplica->fresh()->s3_storage_deleted)->toBeFalse()
        ->and($databaseExecution->fresh()->s3_storage_deleted)->toBeFalse()
        ->and($replica->fresh()->s3_storage_deleted)->toBeFalse()
        ->and($execution->fresh()->s3_cleanup_pending)->toBeTrue();
});
