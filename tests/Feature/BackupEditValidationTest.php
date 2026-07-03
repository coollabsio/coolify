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

function createBackupForEditValidationTest(Team $team, array $overrides = []): ScheduledDatabaseBackup
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $database = StandalonePostgresql::create([
        'name' => 'pg-backup-edit-validation',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    return ScheduledDatabaseBackup::create(array_merge([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        's3_storage_id' => null,
        'database_type' => $database->getMorphClass(),
        'database_id' => $database->id,
        'team_id' => $team->id,
    ], $overrides));
}

function createS3StorageForBackupEditValidationTest(Team|int $team, string $name = 'Backup Edit S3'): S3Storage
{
    return S3Storage::create([
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $team instanceof Team ? $team->id : $team,
    ]);
}

beforeEach(function () {
    if (InstanceSettings::find(0) === null) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('disables S3 backup when saved without a selected S3 storage', function () {
    $backup = createBackupForEditValidationTest($this->team);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->call('submit')
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeFalsy();
    expect($backup->s3_storage_id)->toBeNull();
});

it('cascades to disabling local backup deletion when S3 is force-disabled', function () {
    $backup = createBackupForEditValidationTest($this->team, [
        'disable_local_backup' => true,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->call('submit')
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeFalsy();
    expect($backup->s3_storage_id)->toBeNull();
    expect($backup->disable_local_backup)->toBeFalsy();
});

it('keeps S3 enabled by selecting the only available team storage when none is selected yet', function () {
    createS3StorageForBackupEditValidationTest(Team::factory()->create());
    $s3 = createS3StorageForBackupEditValidationTest($this->team);
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->set('saveS3', true)
        ->call('instantSave')
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeTruthy();
    expect($backup->s3_storage_id)->toBe($s3->id);
});

it('defaults to the first available storage when multiple storages are available', function () {
    $firstS3 = createS3StorageForBackupEditValidationTest($this->team, 'First S3');
    createS3StorageForBackupEditValidationTest($this->team, 'Second S3');
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->assertSet('s3StorageId', $firstS3->id)
        ->set('saveS3', true)
        ->call('instantSave')
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeTruthy();
    expect($backup->s3_storage_id)->toBe($firstS3->id);
});

it('accepts the S3 storage scope passed to the component', function () {
    $s3 = createS3StorageForBackupEditValidationTest(0);
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => collect([$s3])])
        ->set('saveS3', true)
        ->set('s3StorageId', $s3->id)
        ->call('instantSave')
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeTruthy();
    expect($backup->s3_storage_id)->toBe($s3->id);
});

it('shows available S3 storages even when S3 backup is disabled', function () {
    createS3StorageForBackupEditValidationTest($this->team, 'First S3');
    createS3StorageForBackupEditValidationTest($this->team, 'Second S3');
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->assertSee('First S3')
        ->assertSee('Second S3');
});

it('shows disabled S3 storage dropdown when no storages are available', function () {
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->assertSee('No S3 storage available');
});

it('shows when S3 backups are currently disabled', function () {
    createS3StorageForBackupEditValidationTest($this->team);
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->assertSee('S3 Storage')
        ->assertSee('(currently disabled)');
});

it('saves selected S3 storage immediately when it changes', function () {
    createS3StorageForBackupEditValidationTest($this->team, 'First S3');
    $secondS3 = createS3StorageForBackupEditValidationTest($this->team, 'Second S3');
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->set('s3StorageId', $secondS3->id)
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeFalsy();
    expect($backup->s3_storage_id)->toBe($secondS3->id);
});
