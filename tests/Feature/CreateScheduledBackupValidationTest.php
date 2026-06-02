<?php

use App\Livewire\Project\Database\CreateScheduledBackup;
use App\Models\Environment;
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

function createDatabaseForScheduledBackupTest(Team $team): StandalonePostgresql
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return StandalonePostgresql::create([
        'name' => 'pg-scheduled-backup-validation',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function createS3StorageForTeam(Team $team, string $name = 'Test S3'): S3Storage
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

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('rejects enabling S3 backup without a selected S3 storage', function () {
    $database = createDatabaseForScheduledBackupTest($this->team);

    Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', '0 0 * * *')
        ->set('saveToS3', true)
        ->set('s3StorageId', null)
        ->call('submit')
        ->assertDispatched('error');

    expect(ScheduledDatabaseBackup::count())->toBe(0);
});

it('rejects an S3 storage not owned by the current team', function () {
    $database = createDatabaseForScheduledBackupTest($this->team);

    $foreignS3 = createS3StorageForTeam(Team::factory()->create(), 'Foreign S3');

    Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', '0 0 * * *')
        ->set('saveToS3', true)
        ->set('s3StorageId', $foreignS3->id)
        ->call('submit')
        ->assertDispatched('error');

    expect(ScheduledDatabaseBackup::count())->toBe(0);
});

it('rejects an S3 storage that is reassigned after the component is mounted', function () {
    $database = createDatabaseForScheduledBackupTest($this->team);
    $s3 = createS3StorageForTeam($this->team);

    $component = Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', '0 0 * * *')
        ->set('saveToS3', true)
        ->set('s3StorageId', $s3->id);

    $s3->update(['team_id' => Team::factory()->create()->id]);

    $component
        ->call('submit')
        ->assertDispatched('error');

    expect(ScheduledDatabaseBackup::count())->toBe(0);
});

it('rejects an S3 storage that becomes unusable after the component is mounted', function () {
    $database = createDatabaseForScheduledBackupTest($this->team);
    $s3 = createS3StorageForTeam($this->team);

    $component = Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', '0 0 * * *')
        ->set('saveToS3', true)
        ->set('s3StorageId', $s3->id);

    $s3->update(['is_usable' => false]);

    $component
        ->call('submit')
        ->assertDispatched('error');

    expect(ScheduledDatabaseBackup::count())->toBe(0);
});

it('creates a scheduled backup with a valid team-owned S3 storage', function () {
    $database = createDatabaseForScheduledBackupTest($this->team);
    $s3 = createS3StorageForTeam($this->team);

    Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', '0 0 * * *')
        ->set('saveToS3', true)
        ->set('s3StorageId', $s3->id)
        ->call('submit')
        ->assertDispatched('refreshScheduledBackups');

    $backup = ScheduledDatabaseBackup::first();
    expect($backup)->not->toBeNull();
    expect($backup->save_s3)->toBeTruthy();
    expect($backup->s3_storage_id)->toBe($s3->id);
});
