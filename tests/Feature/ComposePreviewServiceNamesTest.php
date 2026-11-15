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

it('keeps the compose service names in a preview deployment', function () {
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 685,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/685',
    ]);

    $services = $this->application->fresh()->parse(pull_request_id: 685, preview_id: $preview->id)->toArray()['services'];
    $previewNetwork = "{$this->application->uuid}-685";

    expect(array_keys($services))->toBe(['search', 'web'])
        ->and($services['search']['container_name'])->toBe("search-{$this->application->uuid}-pr-685")
        ->and($services['web']['networks'])->toBe([$previewNetwork => ['aliases' => ['web-pr-685']]])
        ->and($services['web']['depends_on'])->toBe(['search' => ['condition' => 'service_healthy']])
        ->and($services['web']['environment']['SERVICE_NAME_SEARCH'])->toBe('search');
});

it('renames the services of a preview that joins the predefined network', function () {
    // Production joins the same network, where Docker Compose gives each container its service name as a
    // hostname: a preview service with the same name would also answer for the production service.
    $this->application->settings()->update(['connect_to_docker_network' => true]);
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 685,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/685',
    ]);

    $services = $this->application->fresh()->parse(pull_request_id: 685, preview_id: $preview->id)->toArray()['services'];
    $previewNetwork = "{$this->application->uuid}-685";
    $sharedNetwork = $this->application->destination->network;

    expect(array_keys($services))->toBe(['search-pr-685', 'web-pr-685'])
        ->and($services['web-pr-685']['networks'])->toBe([$previewNetwork => ['aliases' => ['web']], $sharedNetwork => null])
        ->and($services['web-pr-685']['depends_on'])->toBe(['search-pr-685' => ['condition' => 'service_healthy']])
        ->and($services['web-pr-685']['environment']['SERVICE_NAME_SEARCH'])->toBe('search-pr-685');
});

it('renames the services of a preview that joins a compose network with a fixed name', function (string $network) {
    $this->application->update(['docker_compose_raw' => $this->application->docker_compose_raw."\nnetworks:\n{$network}"]);
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 685,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/685',
    ]);

    $services = $this->application->fresh()->parse(pull_request_id: 685, preview_id: $preview->id)->toArray()['services'];

    expect(array_keys($services))->toBe(['search-pr-685', 'web-pr-685']);
})->with([
    'name' => ["  shared:\n    name: shared-network\n"],
    'external' => ["  shared:\n    external: true\n"],
]);

it('keeps the service names when the compose networks belong to the preview project', function () {
    $this->application->update(['docker_compose_raw' => $this->application->docker_compose_raw."\nnetworks:\n  backend:\n    driver: bridge\n"]);
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 685,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/685',
    ]);

    $services = $this->application->fresh()->parse(pull_request_id: 685, preview_id: $preview->id)->toArray()['services'];

    expect(array_keys($services))->toBe(['search', 'web']);
});

it('keeps the services that only another compose file defines', function () {
    $this->application->update(['docker_compose_raw' => <<<'YAML'
services:
  api:
    build: .
    environment:
      DATABASE_HOST: postgres
  postgres:
YAML]);
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 64,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/64',
    ]);

    $services = $this->application->fresh()->parse(pull_request_id: 64, preview_id: $preview->id)->toArray()['services'];

    expect(array_keys($services))->toBe(['api', 'postgres'])
        ->and($services['postgres']['container_name'])->toBe("postgres-{$this->application->uuid}-pr-64");
});

it('uses the same service names in production', function () {
    $services = $this->application->fresh()->parse()->toArray()['services'];

    expect(array_keys($services))->toBe(['search', 'web'])
        ->and($services['search']['networks'])->toBe([$this->application->uuid => null]);
});

it('warns about a service name used as a hostname in a preview on a shared network', function () {
    $this->application->settings()->update(['connect_to_docker_network' => true]);
    $this->application->update(['docker_compose_raw' => <<<'YAML'
services:
  search:
    image: getmeili/meilisearch:v1.11.3
  web:
    image: nginx:alpine
    environment:
      MEILI_URL: http://search:7700
      SEARCH_HOST: ${SERVICE_NAME_SEARCH}
      MODE: searching
  worker:
    image: nginx:alpine
    environment:
      - SEARCH_ADDRESS=search:7700
      - LABEL=research
YAML]);
    $this->application->environment_variables_preview()->create(['key' => 'DATABASE_URL', 'value' => 'postgres://user:secret@search:5432/app', 'is_preview' => true]);

    $warnings = $this->application->fresh()->previewSharedNetworkHostnameWarnings();

    expect($warnings)->toHaveCount(3)
        ->and($warnings[0])->toContain('DATABASE_URL')->toContain('${SERVICE_NAME_SEARCH}')
        ->and($warnings[1])->toContain('MEILI_URL')
        ->and($warnings[2])->toContain('SEARCH_ADDRESS');
});

it('does not warn about service names in a preview on its own network', function () {
    expect($this->application->fresh()->previewSharedNetworkHostnameWarnings())->toBe([]);
});
