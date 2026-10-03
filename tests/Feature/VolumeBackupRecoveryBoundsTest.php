<?php

use App\Jobs\ScheduledJobManager;
use App\Jobs\VolumeBackupJob;
use App\Jobs\VolumeBackupRecoveryJob;
use App\Livewire\Project\Shared\Storages\VolumeBackups;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array{0: Application, 1: LocalPersistentVolume, 2: Server, 3: ScheduledVolumeBackup, 4: S3Storage}
 */
function createRecoveryBoundsBackup(Team $team, array $backupAttributes = []): array
{
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.20',
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
    $s3 = S3Storage::create([
        'name' => 'Volume backups',
        'region' => 'us-east-1',
        'key' => 'AKIARECOVERYKEY',
        'secret' => 'recovery-secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        's3_storage_id' => $s3->id,
        'frequency' => 'daily',
        'save_s3' => true,
        ...$backupAttributes,
    ]);

    return [$application, $volume, $server, $backup, $s3];
}

function createRecoveryBoundsExecution(ScheduledVolumeBackup $backup, array $attributes = []): ScheduledVolumeBackupExecution
{
    return ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $backup->s3_storage_id,
        'status' => 'failed',
        'filename' => '/data/coolify/backups/volumes/test/partial.tar.gz',
        's3_cleanup_pending' => true,
        ...$attributes,
    ]);
}

function failRecoveryBoundsS3Delete(string $awsCode): void
{
    $s3Exception = new S3Exception(
        'The AWS Access Key Id AKIARECOVERYKEY you provided does not exist in our records.',
        new Command('DeleteObject'),
        ['code' => $awsCode],
    );
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')->andThrow(UnableToDeleteFile::atLocation('partial.tar.gz', '', $s3Exception));
    Storage::shouldReceive('build')->andReturn($disk);
}

function signInForRecoveryBounds($testCase, Team $team): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $testCase->actingAs($user);
    session(['currentTeam' => $team]);

    return $user;
}

function makeRecoveryBoundsServerFunctional(Server $server): void
{
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
}

it('doubles the recovery backoff and caps it at six hours', function () {
    expect(collect(range(1, 9))->map(fn (int $attempts) => VolumeBackupRecoveryJob::backoffMinutes($attempts))->all())
        ->toBe([5, 10, 20, 40, 80, 160, 320, 360, 360]);
});

it('records a categorized S3 cleanup failure without throwing or leaking the S3 message', function () {
    Carbon::setTestNow('2026-10-03 12:00:00');
    [, , , $backup] = createRecoveryBoundsBackup(Team::factory()->create());
    $execution = createRecoveryBoundsExecution($backup, ['message' => 'Worker timed out']);
    failRecoveryBoundsS3Delete('InvalidAccessKeyId');
    $logger = Mockery::spy();
    Log::shouldReceive('channel')->with('scheduled-errors')->andReturn($logger);

    (new VolumeBackupRecoveryJob($execution))->handle();

    $execution->refresh();
    expect($execution->s3_cleanup_pending)->toBeTrue()
        ->and($execution->s3_storage_deleted)->toBeFalse()
        ->and($execution->recovery_attempts)->toBe(1)
        ->and($execution->recovery_error)->toBe('s3_auth')
        ->and($execution->recovery_last_attempt_at->equalTo(now()))->toBeTrue()
        ->and($execution->recovery_next_retry_at->equalTo(now()->addMinutes(5)))->toBeTrue()
        ->and($execution->recovery_needs_attention)->toBeFalse()
        ->and($execution->message)->toBe('Worker timed out');
    $logger->shouldHaveReceived('warning')->once()->with(
        'Volume backup recovery failed',
        Mockery::on(fn (array $context): bool => $context['execution_uuid'] === $execution->uuid
            && $context['category'] === 's3_auth'
            && ! str_contains(json_encode($context), 'AKIA')),
    );
});

it('categorizes a missing S3 bucket and grows the backoff on each failure', function () {
    Carbon::setTestNow('2026-10-03 12:00:00');
    [, , , $backup] = createRecoveryBoundsBackup(Team::factory()->create());
    $execution = createRecoveryBoundsExecution($backup);
    failRecoveryBoundsS3Delete('NoSuchBucket');

    VolumeBackupRecoveryJob::recoverWithBounds($execution);
    VolumeBackupRecoveryJob::recoverWithBounds($execution);

    $execution->refresh();
    expect($execution->recovery_error)->toBe('s3_bucket')
        ->and($execution->recovery_attempts)->toBe(2)
        ->and($execution->recovery_next_retry_at->equalTo(now()->addMinutes(10)))->toBeTrue();
});

it('needs attention after the maximum attempts and stops automatic recovery', function () {
    Carbon::setTestNow('2026-10-03 12:00:00');
    config(['constants.coolify.self_hosted' => true]);
    Queue::fake();
    [, , , $backup] = createRecoveryBoundsBackup(Team::factory()->create());
    $execution = createRecoveryBoundsExecution($backup, [
        'recovery_attempts' => VolumeBackupRecoveryJob::MAX_ATTEMPTS - 1,
    ]);
    failRecoveryBoundsS3Delete('AccessDenied');

    VolumeBackupRecoveryJob::recoverWithBounds($execution);
    VolumeBackupRecoveryJob::recoverWithBounds($execution);

    $execution->refresh();
    expect($execution->recovery_needs_attention)->toBeTrue()
        ->and($execution->recovery_attempts)->toBe(VolumeBackupRecoveryJob::MAX_ATTEMPTS)
        ->and($execution->recovery_next_retry_at)->toBeNull();

    Carbon::setTestNow('2026-10-04 12:00:00');
    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(VolumeBackupRecoveryJob::class);
});

it('waits for the backoff before dispatching recovery again', function () {
    Carbon::setTestNow('2026-10-03 12:00:00');
    config(['constants.coolify.self_hosted' => true]);
    Queue::fake();
    [, , , $backup] = createRecoveryBoundsBackup(Team::factory()->create());
    createRecoveryBoundsExecution($backup, [
        'recovery_attempts' => 3,
        'recovery_next_retry_at' => now()->addMinutes(20),
    ]);

    (new ScheduledJobManager)->handle();
    Queue::assertNotPushed(VolumeBackupRecoveryJob::class);

    Carbon::setTestNow('2026-10-03 12:20:00');
    (new ScheduledJobManager)->handle();
    Queue::assertPushed(VolumeBackupRecoveryJob::class, 1);
});

it('cleans the S3 upload even when container recovery fails', function () {
    Carbon::setTestNow('2026-10-03 12:00:00');
    config(['constants.ssh.max_retries' => 1, 'constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    [, , , $backup] = createRecoveryBoundsBackup(Team::factory()->create(), ['stop_during_backup' => true]);
    $execution = createRecoveryBoundsExecution($backup, ['stop_recovery_pending' => true]);
    Process::fake([
        '*' => Process::result(errorOutput: 'ssh: connect to host 203.0.113.20 port 22: Connection refused', exitCode: 255),
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    $filesystem = Mockery::mock(Storage::getFacadeRoot());
    $filesystem->shouldReceive('build')->once()->andReturn($disk);
    Storage::swap($filesystem);

    VolumeBackupRecoveryJob::recoverWithBounds($execution);

    $execution->refresh();
    expect($execution->stop_recovery_pending)->toBeTrue()
        ->and($execution->s3_cleanup_pending)->toBeFalse()
        ->and($execution->s3_storage_deleted)->toBeTrue()
        ->and($execution->recovery_error)->toBe('server_unreachable')
        ->and($execution->recovery_attempts)->toBe(1);
});

it('resets the recovery state after a successful attempt', function () {
    [, , , $backup] = createRecoveryBoundsBackup(Team::factory()->create());
    $execution = createRecoveryBoundsExecution($backup, [
        'recovery_attempts' => 4,
        'recovery_error' => 's3_auth',
        'recovery_last_attempt_at' => now()->subHour(),
        'recovery_next_retry_at' => now()->subMinute(),
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    Storage::shouldReceive('build')->once()->andReturn($disk);

    (new VolumeBackupRecoveryJob($execution))->handle();

    $execution->refresh();
    expect($execution->s3_cleanup_pending)->toBeFalse()
        ->and($execution->s3_storage_deleted)->toBeTrue()
        ->and($execution->recovery_attempts)->toBe(0)
        ->and($execution->recovery_error)->toBeNull()
        ->and($execution->recovery_next_retry_at)->toBeNull();
});

it('only blocks new scheduled backups while stopped containers await recovery', function (array $pending, bool $dispatched) {
    Carbon::setTestNow('2026-10-03 12:00:00');
    config(['constants.coolify.self_hosted' => true]);
    Queue::fake();
    [, , $server, $backup] = createRecoveryBoundsBackup(Team::factory()->create(), [
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    makeRecoveryBoundsServerFunctional($server);
    createRecoveryBoundsExecution($backup, $pending);
    Cache::forget("scheduled-volume-backup:{$backup->id}");

    (new ScheduledJobManager)->handle();

    $dispatched
        ? Queue::assertPushed(VolumeBackupJob::class, fn (VolumeBackupJob $job) => $job->backup->is($backup))
        : Queue::assertNotPushed(VolumeBackupJob::class);
})->with([
    'only S3 cleanup pending' => [['s3_cleanup_pending' => true, 'recovery_needs_attention' => true], true],
    'stopped containers pending' => [['stop_recovery_pending' => true, 's3_cleanup_pending' => false], false],
    'stopped containers need attention' => [['stop_recovery_pending' => true, 'recovery_needs_attention' => true], false],
]);

it('keeps executions with pending recovery during retention cleanup', function () {
    Process::fake([
        '*du -b*' => '128',
        '*' => '',
    ]);
    [, , , $backup] = createRecoveryBoundsBackup(Team::factory()->create(), ['save_s3' => false]);
    $pending = createRecoveryBoundsExecution($backup, [
        'local_storage_deleted' => true,
        's3_uploaded' => null,
    ]);
    $deletable = createRecoveryBoundsExecution($backup, [
        'filename' => null,
        'local_storage_deleted' => true,
        's3_uploaded' => null,
        's3_cleanup_pending' => false,
    ]);

    (new VolumeBackupJob($backup->fresh()))->handle();

    expect($pending->fresh())->not->toBeNull()
        ->and($deletable->fresh())->toBeNull();
});

it('keeps executions with pending recovery when cleaning up deleted entries', function () {
    $team = Team::factory()->create();
    signInForRecoveryBounds($this, $team);
    [$application, $volume, , $backup] = createRecoveryBoundsBackup($team);
    $pending = createRecoveryBoundsExecution($backup, [
        'local_storage_deleted' => true,
        's3_uploaded' => false,
    ]);
    $deletable = createRecoveryBoundsExecution($backup, [
        'local_storage_deleted' => true,
        's3_uploaded' => false,
        's3_cleanup_pending' => false,
    ]);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->call('cleanupDeleted');

    expect($pending->fresh())->not->toBeNull()
        ->and($deletable->fresh())->toBeNull();
});

it('shows the recovery state and retries recovery manually', function () {
    Queue::fake();
    $team = Team::factory()->create();
    signInForRecoveryBounds($this, $team);
    [$application, $volume, , $backup] = createRecoveryBoundsBackup($team);
    $execution = createRecoveryBoundsExecution($backup, [
        'recovery_attempts' => VolumeBackupRecoveryJob::MAX_ATTEMPTS,
        'recovery_error' => 's3_auth',
        'recovery_needs_attention' => true,
    ]);
    Cache::put(VolumeBackupRecoveryJob::dispatchCacheKey($execution->id), true, now()->addMinutes(5));

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application, 'section' => 'executions'])
        ->assertSee('Needs attention (S3 credentials)')
        ->assertSee('Retry recovery')
        ->call('retryRecovery', $execution->id)
        ->assertDispatched('success')
        ->assertSee('Recovery pending');

    $execution->refresh();
    expect($execution->recovery_attempts)->toBe(0)
        ->and($execution->recovery_needs_attention)->toBeFalse()
        ->and($execution->recovery_next_retry_at)->toBeNull()
        ->and(Cache::has(VolumeBackupRecoveryJob::dispatchCacheKey($execution->id)))->toBeFalse();
    Queue::assertPushed(VolumeBackupRecoveryJob::class, fn (VolumeBackupRecoveryJob $job) => $job->execution->is($execution));
});

it('prevents another team from retrying recovery', function () {
    Queue::fake();
    [$application, $volume, , $backup] = createRecoveryBoundsBackup(Team::factory()->create());
    $execution = createRecoveryBoundsExecution($backup, [
        'recovery_attempts' => VolumeBackupRecoveryJob::MAX_ATTEMPTS,
        'recovery_needs_attention' => true,
    ]);
    $otherTeam = Team::factory()->create();
    signInForRecoveryBounds($this, $otherTeam);
    [$otherApplication, $otherVolume] = createRecoveryBoundsBackup($otherTeam);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->assertForbidden();
    Livewire::test(VolumeBackups::class, ['storage' => $otherVolume, 'resource' => $otherApplication])
        ->call('retryRecovery', $execution->id)
        ->assertDispatched('error');

    expect($execution->fresh()->recovery_needs_attention)->toBeTrue()
        ->and($execution->fresh()->recovery_attempts)->toBe(VolumeBackupRecoveryJob::MAX_ATTEMPTS);
    Queue::assertNotPushed(VolumeBackupRecoveryJob::class);
});
