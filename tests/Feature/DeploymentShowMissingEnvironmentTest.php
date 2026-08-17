<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
    ]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => true,
        ]);
    });

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running',
    ]);
});

it('returns 404 when the deployment environment does not exist', function () {
    $this->get(route('project.application.deployment.show', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => 'fbmlga1tumj9ndoy16lal93d',
        'application_uuid' => $this->application->uuid,
        'deployment_uuid' => 'qn5yfvo8clppyt44b75k7dav',
    ]))->assertNotFound();
});

it('returns 404 when the deployment index environment does not exist', function () {
    $this->get(route('project.application.deployment.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => 'fbmlga1tumj9ndoy16lal93d',
        'application_uuid' => $this->application->uuid,
    ]))->assertNotFound();
});

it('returns 404 when the database backup index environment does not exist', function () {
    $this->get(route('project.database.backup.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => 'fbmlga1tumj9ndoy16lal93d',
        'database_uuid' => 'missing-database',
    ]))->assertNotFound();
});

it('returns 404 when the database backup execution environment does not exist', function () {
    $this->get(route('project.database.backup.execution', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => 'fbmlga1tumj9ndoy16lal93d',
        'database_uuid' => 'missing-database',
        'backup_uuid' => 'missing-backup',
    ]))->assertNotFound();
});
