<?php

use App\Events\BackupCreated;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\VolumeBackupJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMysql;
use App\Models\Team;
use App\Notifications\Database\BackupSuccessWithS3Warning;
use App\Notifications\VolumeBackup\BackupSuccess as VolumeBackupSuccess;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const S3_DESTINATION_SECRETS = [
    'first-access-id-7731',
    'first-hidden-value-9921',
    'second-access-id-4482',
    'second-hidden-value-5513',
];

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false, 'broadcasting.default' => 'null']);
    defined('CURLOPT_RESOLVE') || define('CURLOPT_RESOLVE', 10203);
    Event::fake([BackupCreated::class]);
    Notification::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    // The notification fake only records notifications that have at least one enabled channel.
    $this->team->emailNotificationSettings->update(['smtp_enabled' => true, 'backup_failure_email_notifications' => true]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
        'ip' => '203.0.113.10',
    ]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $this->storages = collect(['first', 'second'])->map(fn (string $name): S3Storage => S3Storage::create([
        'name' => "{$name} storage",
        'region' => 'us-east-1',
        'key' => "{$name}-access-id-".($name === 'first' ? '7731' : '4482'),
        'secret' => "{$name}-hidden-value-".($name === 'first' ? '9921' : '5513'),
        'bucket' => "{$name}-bucket",
        'endpoint' => 'https://s3.amazonaws.com',
        'team_id' => $this->team->id,
        'is_usable' => true,
    ]));
    $this->uploadError = 'mc: upload rejected for '.implode(' / ', S3_DESTINATION_SECRETS);
});

function expectNoS3DestinationSecrets(?string $text): void
{
    foreach (S3_DESTINATION_SECRETS as $secret) {
        expect((string) $text)->not->toContain($secret);
    }
}

it('redacts the credentials of every S3 destination from a database backup upload failure', function () {
    $database = create_standalone_mysql($this->environment->id, $this->destination, [
        'mysql_root_password' => str_repeat('x', 5),
        'mysql_database' => 'app',
        'status' => 'running:healthy',
    ]);
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        's3_storage_id' => $this->storages[0]->id,
        'database_type' => StandaloneMysql::class,
        'database_id' => $database->id,
        'team_id' => $this->team->id,
        'databases_to_backup' => 'app',
    ]);
    $backup->syncS3Storages($this->storages->pluck('id')->all());
    Process::fake(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return Process::result(output: str_contains($command, 'du -b') ? '128' : '');
    });
    $uploadError = $this->uploadError;
    $job = Mockery::mock(DatabaseBackupJob::class.'[upload_to_s3]', [$backup->fresh()])->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('upload_to_s3')->andReturnUsing(fn () => throw new RuntimeException($uploadError));

    $job->handle();

    $execution = $backup->executions()->sole();
    expect($execution->status)->toBe('success')
        ->and($execution->message)->toContain('mc: upload rejected')
        ->and($execution->s3Replicas()->count())->toBe(2);
    expectNoS3DestinationSecrets($execution->message);
    $execution->s3Replicas()->get()->each(function ($replica) {
        expect($replica->message)->toContain('mc: upload rejected');
        expectNoS3DestinationSecrets($replica->message);
    });
    Notification::assertSentTo($this->team, BackupSuccessWithS3Warning::class, function (BackupSuccessWithS3Warning $notification) {
        expect($notification->s3_error)->toContain('mc: upload rejected');
        expectNoS3DestinationSecrets($notification->s3_error);

        return true;
    });
});

it('redacts the credentials of every S3 destination from a volume backup upload failure', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => 'app-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    $backup->syncS3Storages($this->storages->pluck('id')->all());
    $sshDisk = Storage::fake('ssh-keys');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('files')->zeroOrMoreTimes()->andReturn([]);
    $disk->shouldReceive('delete')->zeroOrMoreTimes()->andReturnTrue();
    Storage::shouldReceive('disk')->with('ssh-keys')->andReturn($sshDisk);
    Storage::shouldReceive('build')->zeroOrMoreTimes()->andReturn($disk);
    Process::fake([
        '*du -b*' => '128',
        '*mc cp*' => Process::result(errorOutput: $this->uploadError, exitCode: 1),
        '*' => '',
    ]);

    (new VolumeBackupJob($backup->fresh()))->handle();

    $execution = ScheduledVolumeBackupExecution::query()->sole();
    expect($execution->status)->toBe('success')
        ->and($execution->message)->toContain('mc: upload rejected')
        ->and($execution->s3Replicas()->count())->toBe(2);
    expectNoS3DestinationSecrets($execution->message);
    $execution->s3Replicas()->get()->each(function ($replica) {
        expect($replica->message)->toContain('mc: upload rejected');
        expectNoS3DestinationSecrets($replica->message);
    });
    Notification::assertSentTo($this->team, VolumeBackupSuccess::class, function (VolumeBackupSuccess $notification) {
        expect($notification->warning)->toContain('mc: upload rejected');
        expectNoS3DestinationSecrets($notification->warning);

        return true;
    });
});
