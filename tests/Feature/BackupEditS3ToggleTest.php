<?php

use App\Livewire\Project\Database\BackupEdit;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createDatabaseForBackupEditTest(Team $team): StandalonePostgresql
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return StandalonePostgresql::create([
        'name' => 'pg-backup-edit',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function createS3StorageForBackupEditTest(Team $team, string $name = 'Test S3'): S3Storage
{
    return S3Storage::create([
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $team->id,
    ]);
}

function createScheduledBackupForEditTest(StandalonePostgresql $database, Team $team): ScheduledDatabaseBackup
{
    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill([
        'enabled' => true,
        'save_s3' => false,
        's3_storage_id' => null,
        'frequency' => '0 0 * * *',
        'database_backup_retention_amount_locally' => 0,
        'database_backup_retention_days_locally' => 0,
        'database_backup_retention_max_storage_locally' => 0,
        'database_backup_retention_amount_s3' => 0,
        'database_backup_retention_days_s3' => 0,
        'database_backup_retention_max_storage_s3' => 0,
        'dump_all' => false,
        'timeout' => 3600,
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
    ]);
    $backup->save();

    return $backup;
}

beforeEach(function () {
    $instanceSettings = new InstanceSettings;
    $instanceSettings->forceFill([
        'id' => 0,
        'is_registration_enabled' => true,
        'smtp_enabled' => true,
        'smtp_host' => 'coolify-mail',
        'smtp_port' => 1025,
        'smtp_from_address' => 'hi@localhost.com',
        'smtp_from_name' => 'Coolify',
    ]);
    $instanceSettings->save();
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('selects an available S3 storage instead of silently disabling the toggle when the selection is invalid', function () {
    $database = createDatabaseForBackupEditTest($this->team);
    $backup = createScheduledBackupForEditTest($database, $this->team);
    $s3 = createS3StorageForBackupEditTest($this->team);

    Livewire::test(BackupEdit::class, ['backup' => $backup, 's3s' => collect([$s3])])
        ->set('s3StorageId', 999999)
        ->set('saveS3', true)
        ->call('instantSave')
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeTrue();
    expect($backup->s3_storage_id)->toBe($s3->id);
});

it('disables the S3 toggle when no S3 storage is available', function () {
    $database = createDatabaseForBackupEditTest($this->team);
    $backup = createScheduledBackupForEditTest($database, $this->team);

    Livewire::test(BackupEdit::class, ['backup' => $backup, 's3s' => collect()])
        ->set('saveS3', true)
        ->call('instantSave');

    $backup->refresh();
    expect($backup->save_s3)->toBeFalse();
    expect($backup->s3_storage_id)->toBeNull();
});
