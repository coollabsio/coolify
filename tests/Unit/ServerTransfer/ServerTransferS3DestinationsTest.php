<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Services\ServerTransfer\ServerTransferExporter;
use App\Services\ServerTransfer\ServerTransferImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'fqdn' => 'https://coolify-a.test']);

    $this->team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '10.56.0.10',
    ]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-data',
        'mount_path' => '/app/data',
        'resource_type' => $this->application->getMorphClass(),
        'resource_id' => $this->application->id,
    ]);

    StandalonePostgresql::withoutEvents(function () use ($environment, $destination) {
        $database = new StandalonePostgresql;
        $database->forceFill([
            'name' => 'app-db',
            'postgres_user' => 'postgres',
            'postgres_password' => 'secret',
            'postgres_db' => 'app',
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
        $database->uuid = new_public_id();
        $database->save();
        $this->database = $database;
    });

    $this->firstS3 = S3Storage::create([
        'name' => 'first', 'region' => 'us-east-1', 'key' => 'k1', 'secret' => 's1',
        'bucket' => 'first-bucket', 'endpoint' => 'https://first.example.com', 'team_id' => $this->team->id,
    ]);
    $this->secondS3 = S3Storage::create([
        'name' => 'second', 'region' => 'us-east-1', 'key' => 'k2', 'secret' => 's2',
        'bucket' => 'second-bucket', 'endpoint' => 'https://second.example.com', 'team_id' => $this->team->id,
    ]);

    $this->databaseBackup = ScheduledDatabaseBackup::create([
        'team_id' => $this->team->id,
        'save_s3' => true,
        'frequency' => '0 2 * * *',
        's3_storage_id' => $this->secondS3->id,
        'database_type' => $this->database->getMorphClass(),
        'database_id' => $this->database->id,
    ]);
    $this->databaseBackup->syncS3Storages([$this->firstS3->id, $this->secondS3->id]);

    $this->volumeBackup = $volume->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'save_s3' => true,
        'frequency' => '0 3 * * *',
        's3_storage_id' => $this->secondS3->id,
    ]);
    $this->volumeBackup->syncS3Storages([$this->firstS3->id, $this->secondS3->id]);
});

/**
 * @return list<string> Buckets of the backup's destinations, primary first.
 */
function transferredBackupBuckets(ScheduledDatabaseBackup|ScheduledVolumeBackup $backup): array
{
    return $backup->selectedS3Storages()->pluck('bucket')->all();
}

function importTransferBundleIntoNewTeam(array $bundle, $context): Team
{
    $targetTeam = Team::factory()->create();
    $context->application->forceDelete();
    $context->database->forceDelete();
    $context->server->forceDelete();

    app(ServerTransferImporter::class)->import($bundle, teamId: $targetTeam->id);

    return $targetTeam;
}

test('transfer keeps every S3 destination of database and volume backups', function () {
    $bundle = app(ServerTransferExporter::class)->export($this->server);

    expect(collect($bundle['s3_storages'])->pluck('uuid')->sort()->values()->all())
        ->toEqual(collect([$this->firstS3->uuid, $this->secondS3->uuid])->sort()->values()->all());

    $targetTeam = importTransferBundleIntoNewTeam($bundle, $this);

    $databaseBackup = ScheduledDatabaseBackup::where('team_id', $targetTeam->id)->sole();
    $volumeBackup = ScheduledVolumeBackup::where('team_id', $targetTeam->id)->sole();

    expect(transferredBackupBuckets($databaseBackup))->toBe(['second-bucket', 'first-bucket'])
        ->and($databaseBackup->save_s3)->toBeTruthy()
        ->and($databaseBackup->s3->team_id)->toBe($targetTeam->id)
        ->and(transferredBackupBuckets($volumeBackup))->toBe(['second-bucket', 'first-bucket'])
        ->and($volumeBackup->save_s3)->toBeTrue()
        ->and($volumeBackup->s3->team_id)->toBe($targetTeam->id);
});

test('transfer imports bundles that only have the primary S3 destination', function () {
    $bundle = app(ServerTransferExporter::class)->export($this->server);
    data_forget($bundle, 'projects.*.environments.*.databases.*.scheduled_backups.*.s3_storage_uuids');
    data_forget($bundle, 'volume_backups.*.s3_storage_uuids');
    expect(data_get($bundle, 'volume_backups.0'))->not->toHaveKey('s3_storage_uuids');

    $targetTeam = importTransferBundleIntoNewTeam($bundle, $this);

    expect(transferredBackupBuckets(ScheduledDatabaseBackup::where('team_id', $targetTeam->id)->sole()))->toBe(['second-bucket'])
        ->and(transferredBackupBuckets(ScheduledVolumeBackup::where('team_id', $targetTeam->id)->sole()))->toBe(['second-bucket']);
});
