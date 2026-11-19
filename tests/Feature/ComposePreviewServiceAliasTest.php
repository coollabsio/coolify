<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  search:
    image: getmeili/meilisearch:v1.11.3
  web:
    image: nginx:alpine
    environment:
      MEILI_URL: http://search:7700
    depends_on:
      search:
        condition: service_healthy
YAML,
    ]);
});

it('keeps the compose service name as a hostname on the preview network', function () {
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 685,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/685',
    ]);

    $services = $this->application->fresh()->parse(pull_request_id: 685, preview_id: $preview->id)->toArray()['services'];
    $previewNetwork = "{$this->application->uuid}-685";

    expect(array_keys($services))->toBe(['search-pr-685', 'web-pr-685'])
        ->and($services['search-pr-685']['networks'])->toBe([$previewNetwork => ['aliases' => ['search']]])
        ->and($services['web-pr-685']['networks'])->toBe([$previewNetwork => ['aliases' => ['web']]]);
});

it('adds no alias to the network of a production deployment', function () {
    $services = $this->application->fresh()->parse()->toArray()['services'];

    expect($services['search']['networks'])->toBe([$this->application->uuid => null]);
});
