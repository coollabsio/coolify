<?php

use App\Jobs\DeleteResourceJob;
use App\Models\DatabaseBackupS3Replica;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    Queue::fake();
    Notification::fake();
    Process::fake();

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->s3 = S3Storage::create([
        'name' => 'backups',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'backups',
        'endpoint' => 'https://1.1.1.1',
        'team_id' => $this->team->id,
        'is_usable' => true,
    ]);
    $this->s3Unreachable = false;
    Storage::partialMock()->shouldReceive('build')->andReturnUsing(fn () => $this->s3Unreachable
        ? throw new RuntimeException('S3 is unreachable')
        : $this->bucket);
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    $this->bucket = Storage::fake('database-backups-s3');
});

function createBackupWithDump(mixed $database, S3Storage $s3, string $filename): ScheduledDatabaseBackupExecution
{
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $s3->team_id,
        'frequency' => 'daily',
        'save_s3' => true,
        's3_storage_id' => $s3->id,
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
    ]);
    $execution = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'database_name' => 'postgres',
        'filename' => $filename,
        's3_uploaded' => true,
    ]);
    DatabaseBackupS3Replica::create([
        'execution_id' => $execution->id,
        's3_storage_id' => $s3->id,
        's3_uploaded' => true,
    ]);

    return $execution;
}

function createDeletedPostgresql(object $test): StandalonePostgresql
{
    $database = StandalonePostgresql::create([
        'name' => 'deleted-pg',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
    ]);
    $database->delete();

    return $database;
}

function removesRemoteFile(string $name): Closure
{
    return fn ($process): bool => str_contains(remoteProcessScript($process), 'rm -f')
        && str_contains(remoteProcessScript($process), $name);
}

it('deletes the database backup files on the server and on S3 when a database is deleted', function () {
    $database = createDeletedPostgresql($this);
    $filename = '/data/coolify/backups/databases/team/deleted-pg/pg-dump-postgres-1.dmp';
    $execution = createBackupWithDump($database, $this->s3, $filename);
    $this->bucket->put($filename, 'dump');

    (new DeleteResourceJob($database, deleteBackups: true))->handle();

    Process::assertRan(removesRemoteFile('pg-dump-postgres-1.dmp'));
    expect($this->bucket->exists($filename))->toBeFalse()
        ->and(ScheduledDatabaseBackup::query()->find($execution->scheduled_database_backup_id))->toBeNull()
        ->and($execution->fresh())->toBeNull();
    Notification::assertNotSentTo($this->team, GeneralNotification::class, fn (GeneralNotification $notification): bool => str_contains($notification->message, 'backup files'));
});

it('keeps the database backup files but removes their records when the backups are kept', function () {
    $database = createDeletedPostgresql($this);
    $filename = '/data/coolify/backups/databases/team/deleted-pg/pg-dump-postgres-1.dmp';
    $execution = createBackupWithDump($database, $this->s3, $filename);
    $this->bucket->put($filename, 'dump');

    (new DeleteResourceJob($database))->handle();

    Process::assertDidntRun(removesRemoteFile('pg-dump-postgres-1.dmp'));
    expect($this->bucket->exists($filename))->toBeTrue()
        ->and(ScheduledDatabaseBackup::query()->find($execution->scheduled_database_backup_id))->toBeNull()
        ->and($execution->fresh())->toBeNull();
});

it('deletes the backup files of the databases of a deleted service', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $serviceDatabase = ServiceDatabase::create([
        'service_id' => $service->id,
        'name' => 'mariadb',
        'image' => 'mariadb:11',
    ]);
    $filename = '/data/coolify/backups/databases/team/mariadb/mariadb-dump-wordpress-1.dmp';
    $execution = createBackupWithDump($serviceDatabase, $this->s3, $filename);
    $this->bucket->put($filename, 'dump');
    $service->delete();

    (new DeleteResourceJob($service, deleteBackups: true))->handle();

    Process::assertRan(removesRemoteFile('mariadb-dump-wordpress-1.dmp'));
    expect($this->bucket->exists($filename))->toBeFalse()
        ->and(ScheduledDatabaseBackup::query()->find($execution->scheduled_database_backup_id))->toBeNull()
        ->and($execution->fresh())->toBeNull();
});

it('deletes the database and tells the team when its backup files cannot be deleted', function () {
    $this->team->webhookNotificationSettings()->update([
        'webhook_enabled' => true,
        'webhook_url' => 'https://example.com/webhook',
    ]);
    $database = createDeletedPostgresql($this);
    $execution = createBackupWithDump($database, $this->s3, '/data/coolify/backups/databases/team/deleted-pg/pg-dump-postgres-1.dmp');
    $this->s3Unreachable = true;

    (new DeleteResourceJob($database, deleteBackups: true))->handle();

    expect(StandalonePostgresql::withTrashed()->find($database->id))->toBeNull()
        ->and($execution->fresh())->toBeNull();
    Notification::assertSentTo(
        $this->team,
        GeneralNotification::class,
        fn (GeneralNotification $notification): bool => ! $notification->success
            && str_contains($notification->message, 'some of its backup files could not be deleted'),
    );
});
