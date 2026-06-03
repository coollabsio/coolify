<?php

use App\Livewire\Project\Database\BackupEdit;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
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

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 's3s' => $this->team->s3s])
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

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 's3s' => $this->team->s3s])
        ->call('submit')
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeFalsy();
    expect($backup->s3_storage_id)->toBeNull();
    expect($backup->disable_local_backup)->toBeFalsy();
});
