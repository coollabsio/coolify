<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);
});

test('parseDockerComposeFile creates service database for dockercompose application services', function () {
    $server = \App\Models\Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'network' => 'coolify-test',
    ]);

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'build_pack' => 'dockercompose',
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'docker_compose_raw' => <<<'YAML'
services:
  db:
    image: postgres:15-alpine
YAML,
    ]);

    parseDockerComposeFile($application, true);

    expect(ServiceDatabase::query()
        ->where('application_id', $application->id)
        ->where('name', 'db')
        ->exists()
    )->toBeTrue();
});

test('removes ServiceDatabase when database service is deleted from compose', function () {
    $server = \App\Models\Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'network' => 'coolify-test',
    ]);
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'build_pack' => 'dockercompose',
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $application->docker_compose_raw = "services:\n  db:\n    image: postgres:15-alpine\n";
    $application->save();
    parseDockerComposeFile($application, true);

    expect(ServiceDatabase::where('application_id', $application->id)->where('name', 'db')->exists())->toBeTrue();

    $application->docker_compose_raw = "services:\n  app:\n    image: nginx:alpine\n";
    $application->save();
    parseDockerComposeFile($application, false);

    expect(ServiceDatabase::where('application_id', $application->id)->where('name', 'db')->exists())->toBeFalse();
});

