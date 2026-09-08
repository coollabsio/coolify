<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('selects the private service network for Traefik routed compose services', function () {
    Bus::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:latest
    networks:
      - custom-network
networks:
  custom-network: {}
YAML,
    ]);

    ServiceApplication::create([
        'name' => 'app',
        'service_id' => $service->id,
        'fqdn' => 'https://example.com',
    ]);

    $parsedCompose = serviceParser($service);
    $labels = collect(data_get($parsedCompose, 'services.app.labels'));

    expect($labels->values()->all())->toContain("traefik.docker.network={$service->uuid}");
});
