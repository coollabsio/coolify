<?php

use App\Jobs\DatabaseBackupJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\ServiceDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use App\Jobs\ApplicationDeploymentJob;

beforeEach(function () {
    $this->server = Server::factory()->create([
        'id' => 0,
        'ip' => '1.2.3.4',
        'team_id' => 0,
    ]);

    $this->project = Project::factory()->create(['team_id' => 0]);
    $environment = $this->project->environments()->create(['name' => 'production']);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $this->server->id,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => 'version: "3"\nservices:\n  db:\n    image: postgres',
    ]);
});

test('it resolves container name for main application', function () {
    $backup = \App\Models\ScheduledDatabaseBackup::create([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => '0 0 * * *',
        'database_id' => 1,
        'database_type' => ServiceDatabase::class,
        'team_id' => 0,
    ]);

    $database = ServiceDatabase::create([
        'id' => 1,
        'application_id' => $this->application->id,
        'name' => 'db',
    ]);

    $job = new DatabaseBackupJob($backup);
    
    $expectedName = "{$this->application->uuid}-db";
});

test('it blocks backup if deployment is running', function () {
    $backup = \App\Models\ScheduledDatabaseBackup::create([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => '0 0 * * *',
        'database_id' => 1,
        'database_type' => ServiceDatabase::class,
        'team_id' => 0,
    ]);

    $database = ServiceDatabase::create([
        'id' => 1,
        'application_id' => $this->application->id,
        'name' => 'db',
    ]);


    \Illuminate\Support\Facades\Cache::put("deployment:pipeline:{$this->application->id}", true);
    
    $job = new DatabaseBackupJob($backup);
    $job->handle();
    

    $execution = \App\Models\ScheduledDatabaseBackupExecution::where('scheduled_database_backup_id', $backup->id)->first();
    expect($execution->status)->toBe('failed');
    expect($execution->message)->toContain('Deployment in progress');
});

test('it blocks deployment if backup is running', function () {
     $database = ServiceDatabase::create([
        'application_id' => $this->application->id,
        'name' => 'db',
    ]);


    \Illuminate\Support\Facades\Cache::put("backup:running:{$this->application->id}", true);
    

    $canQueue = next_queuable($this->server->id, $this->application->id);
    
    expect($canQueue)->toBeFalse();
});

test('it correctly resolves preview container name', function () {
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 123,
    ]);

    $database = ServiceDatabase::create([
        'application_id' => $this->application->id,
        'application_preview_id' => $preview->id,
        'name' => 'db-pr',
    ]);

    $backup = \App\Models\ScheduledDatabaseBackup::create([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => '0 0 * * *',
        'database_id' => $database->id,
        'database_type' => ServiceDatabase::class,
        'team_id' => 0,
    ]);

    $job = new DatabaseBackupJob($backup);
    
});
