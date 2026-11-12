<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('renders unavailable S3 storage only for S3-enabled database backups', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = StandalonePostgresql::create([
        'name' => 'Orders-Primary',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $database->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
        'save_s3' => true,
        's3_storage_id' => null,
    ]);
    $database->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'weekly',
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    $parameters = [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'database_uuid' => $database->uuid,
    ];

    $response = $this->get(route('project.database.backup.index', $parameters))
        ->assertOk()
        ->assertSee('Unavailable')
        ->assertSee('Local only');

    // Only the S3-enabled schedule without a linked storage shows "Unavailable".
    expect(substr_count($response->getContent(), 'Unavailable'))->toBe(1);
});
