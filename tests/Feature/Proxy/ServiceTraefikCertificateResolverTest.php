<?php

use App\Enums\ProxyTypes;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function serviceTraefikCertificateResolverLabels(?string $resourceResolver): Collection
{
    Bus::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $server->settings->update(['traefik_certificate_resolver' => 'letsencrypt']);
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
YAML,
    ]);

    ServiceApplication::create([
        'name' => 'app',
        'service_id' => $service->id,
        'fqdn' => 'https://example.com',
        'traefik_certificate_resolver' => $resourceResolver,
    ]);

    return collect(data_get(serviceParser($service->fresh()), 'services.app.labels'))->values();
}

it('uses the server certificate resolver when the service application has no override', function () {
    $labels = serviceTraefikCertificateResolverLabels(null);

    expect($labels->filter(fn ($label) => str_contains($label, '.tls.certresolver=letsencrypt')))->not->toBeEmpty();
});

it('omits the certificate resolver when the service application uses none', function () {
    $labels = serviceTraefikCertificateResolverLabels('none');

    expect($labels->filter(fn ($label) => str_contains($label, '.tls=true')))->not->toBeEmpty()
        ->and($labels->filter(fn ($label) => str_contains($label, 'certresolver')))->toBeEmpty();
});

it('uses the custom certificate resolver of the service application', function () {
    $labels = serviceTraefikCertificateResolverLabels('cloudflare');

    expect($labels->filter(fn ($label) => str_contains($label, '.tls.certresolver=cloudflare')))->not->toBeEmpty()
        ->and($labels->filter(fn ($label) => str_contains($label, 'certresolver=letsencrypt')))->toBeEmpty();
});
