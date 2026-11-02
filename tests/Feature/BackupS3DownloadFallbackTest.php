<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $database = StandalonePostgresql::create([
        'name' => 's3-download-pg',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
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

    $this->primaryS3 = createS3ForDownloadFallbackTest($this->team, 'primary');
    $this->replicaS3 = createS3ForDownloadFallbackTest($this->team, 'replica');

    $this->databaseBackup = ScheduledDatabaseBackup::create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
        'save_s3' => true,
        'disable_local_backup' => true,
        's3_storage_id' => $this->primaryS3->id,
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
    ]);
    $this->databaseExecution = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $this->databaseBackup->id,
        'status' => 'success',
        'database_name' => 'postgres',
        'filename' => '/data/coolify/backups/databases/team/pg/pg-dump-postgres-1.dmp',
        'local_storage_deleted' => true,
    ]);

    $this->volumeBackup = $volume->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
    ]);
    $this->volumeExecution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $this->volumeBackup->id,
        's3_storage_id' => $this->primaryS3->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/team/app/volume-app-data-1.tar.gz',
        'local_storage_deleted' => true,
    ]);
});

function createS3ForDownloadFallbackTest(Team $team, string $name, bool $isUsable = true): S3Storage
{
    return S3Storage::create([
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => "{$name}-{$team->id}-bucket",
        'endpoint' => 'https://1.1.1.1',
        'team_id' => $team->id,
        'is_usable' => $isUsable,
    ]);
}

function signInForDownloadFallbackTest($testCase, Team $team, string $role = 'owner'): void
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role]);
    $testCase->actingAs($user);
    session(['currentTeam' => $team]);
}

/**
 * @return array{0: string, 1: ScheduledDatabaseBackupExecution|ScheduledVolumeBackupExecution}
 */
function downloadFallbackTarget($testCase, string $type): array
{
    return $type === 'database'
        ? ['download.backup', $testCase->databaseExecution]
        : ['download.volume-backup', $testCase->volumeExecution];
}

function assertPresignedBackupRedirect($response, S3Storage $storage, string $filename): void
{
    $location = $response->assertRedirect()->headers->get('Location');
    $url = parse_url($location);
    parse_str($url['query'], $query);

    expect("{$url['scheme']}://{$url['host']}{$url['path']}")->toBe("https://1.1.1.1/{$storage->bucket}".$filename)
        ->and($query)->toHaveKeys(['X-Amz-Signature', 'X-Amz-Expires'])
        ->and((int) $query['X-Amz-Expires'])->toBeLessThanOrEqual(15 * 60)
        ->and($query['response-content-disposition'])->toContain('attachment; filename="'.basename($filename).'"');
}

it('redirects to a presigned URL of the primary S3 copy when no local copy exists', function (string $type) {
    [$route, $execution] = downloadFallbackTarget($this, $type);
    $execution->s3Replicas()->create(['s3_storage_id' => $this->replicaS3->id, 's3_uploaded' => true]);
    $execution->s3Replicas()->create(['s3_storage_id' => $this->primaryS3->id, 's3_uploaded' => true]);
    signInForDownloadFallbackTest($this, $this->team);

    assertPresignedBackupRedirect($this->get(route($route, $execution->id)), $this->primaryS3, $execution->filename);
})->with(['database', 'volume']);

it('uses a replica when the primary S3 copy is deleted or its storage is unusable', function (string $type, bool $primaryDeleted) {
    [$route, $execution] = downloadFallbackTarget($this, $type);
    if (! $primaryDeleted) {
        $this->primaryS3->update(['is_usable' => false]);
    }
    $execution->s3Replicas()->create(['s3_storage_id' => $this->primaryS3->id, 's3_uploaded' => true, 's3_storage_deleted' => $primaryDeleted]);
    $execution->s3Replicas()->create(['s3_storage_id' => $this->replicaS3->id, 's3_uploaded' => true]);
    signInForDownloadFallbackTest($this, $this->team);

    assertPresignedBackupRedirect($this->get(route($route, $execution->id)), $this->replicaS3, $execution->filename);
})->with(['database', 'volume'])->with(['primary deleted' => true, 'primary unusable' => false]);

it('keeps the not found message when no usable S3 copy exists', function (string $type, string $message) {
    [$route, $execution] = downloadFallbackTarget($this, $type);
    $execution->s3Replicas()->create(['s3_storage_id' => $this->primaryS3->id, 's3_uploaded' => false]);
    $execution->s3Replicas()->create(['s3_storage_id' => $this->replicaS3->id, 's3_uploaded' => true, 's3_storage_deleted' => true]);
    signInForDownloadFallbackTest($this, $this->team);

    $this->get(route($route, $execution->id))->assertNotFound()->assertJson(['message' => $message]);
})->with([
    'database' => ['database', 'Backup not available locally, but available on S3.'],
    'volume' => ['volume', 'Backup not found locally on the server.'],
]);

it('never uses an S3 storage of another team', function (string $type) {
    [$route, $execution] = downloadFallbackTarget($this, $type);
    $foreignS3 = createS3ForDownloadFallbackTest($this->otherTeam, 'foreign');
    $execution->s3Replicas()->create(['s3_storage_id' => $foreignS3->id, 's3_uploaded' => true]);
    signInForDownloadFallbackTest($this, $this->team);

    $this->get(route($route, $execution->id))->assertNotFound();
})->with(['database', 'volume']);

it('forbids members and other teams from getting a presigned URL', function (string $type, string $user) {
    [$route, $execution] = downloadFallbackTarget($this, $type);
    $execution->s3Replicas()->create(['s3_storage_id' => $this->primaryS3->id, 's3_uploaded' => true]);
    $user === 'member'
        ? signInForDownloadFallbackTest($this, $this->team, 'member')
        : signInForDownloadFallbackTest($this, $this->otherTeam);

    $this->get(route($route, $execution->id))->assertForbidden();
})->with(['database', 'volume'])->with(['member', 'other team owner']);
