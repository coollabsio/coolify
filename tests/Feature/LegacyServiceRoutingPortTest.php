<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

it('only preserves an unambiguous saved upstream for the same legacy container', function (array $labels, ?int $expected, array $rawChanges = [], array $savedChanges = [], ?string $type = null) {
    $raw = array_replace(['image' => 'httpd:2.4-alpine', 'environment' => ['SERVICE_URL_WEB']], $rawChanges);
    $saved = array_replace(['image' => 'httpd:2.4-alpine', 'labels' => $labels], $savedChanges);
    $service = new Service;
    $service->forceFill([
        'service_type' => $type,
        'docker_compose_raw' => Yaml::dump(['services' => ['web' => $raw]]),
        'docker_compose' => Yaml::dump(['services' => ['web' => $saved, 'other' => ['image' => 'httpd:2.4-alpine', 'labels' => ['traefik.http.services.other.loadbalancer.server.port=9000']]]]),
    ]);
    $application = new ServiceApplication(['name' => 'web']);
    $application->setRelation('service', $service);

    expect($application->getRequiredPort())->toBe($expected);
})->with([
    'Traefik list' => [['traefik.http.services.web.loadbalancer.server.port=3000'], 3000],
    'Traefik map' => [['traefik.http.services.web.loadbalancer.server.port' => '3000'], 3000],
    'Caddy list' => [['caddy_0.handle_path.0_reverse_proxy={{upstreams 3000}}'], 3000],
    'Caddy map' => [['caddy_0.handle_path.0_reverse_proxy' => '{{upstreams 3000}}'], 3000],
    'matching proxies' => [['traefik.http.services.web.loadbalancer.server.port=3000', 'caddy_0.handle_path.0_reverse_proxy={{upstreams 3000}}'], 3000],
    'conflicting proxies' => [['traefik.http.services.web.loadbalancer.server.port=3000', 'caddy_0.handle_path.0_reverse_proxy={{upstreams 4000}}'], null],
    'multiple Traefik ports' => [['traefik.http.services.web.loadbalancer.server.port=3000', 'traefik.http.services.api.loadbalancer.server.port=4000'], null],
    'malformed port' => [['traefik.http.services.web.loadbalancer.server.port=3000oops'], null],
    'out of range' => [['traefik.http.services.web.loadbalancer.server.port=65536'], null],
    'negative' => [['traefik.http.services.web.loadbalancer.server.port=-1'], null],
    'mixed malformed' => [['traefik.http.services.web.loadbalancer.server.port=3000', 'caddy_0.handle_path.0_reverse_proxy=oops'], null],
    'map bare FQDN' => [['traefik.http.services.web.loadbalancer.server.port=3000'], 3000, ['environment' => ['SERVICE_FQDN_WEB' => null]]],
    'UDP only preserves HTTP upstream' => [['traefik.http.services.web.loadbalancer.server.port=3000'], 3000, ['expose' => ['53/udp']]],
    'zero' => [['traefik.http.services.web.loadbalancer.server.port=0'], null],
    'malformed Caddy' => [['caddy_0.handle_path.0_reverse_proxy={{upstreams 3000oops}}'], null],
    'discovery Caddy' => [['caddy_0.handle_path.0_reverse_proxy={{upstreams}}'], null],
    'no cross container' => [[], null],
    'changed image' => [['traefik.http.services.web.loadbalancer.server.port=3000'], null, [], ['image' => 'httpd:latest']],
    'missing saved image' => [['traefik.http.services.web.loadbalancer.server.port=3000'], null, [], ['image' => null]],
    'no direct declaration' => [['traefik.http.services.web.loadbalancer.server.port=3000'], null, ['environment' => ['URL=${SERVICE_URL_WEB}']]],
    'explicit magic wins' => [['traefik.http.services.web.loadbalancer.server.port=3000'], 8080, ['environment' => ['SERVICE_URL_WEB_8080']]],
    'Compose port wins' => [['traefik.http.services.web.loadbalancer.server.port=3000'], 8080, ['expose' => [8080]]],
    'known template excluded' => [['traefik.http.services.web.loadbalancer.server.port=3000'], 80, [], [], 'wordpress-without-database'],
]);

it('preserves legacy upstreams across repeated parsing without adding public ports', function (int $version, ?int $override) {
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['generate_exact_labels' => false]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $service = Service::factory()->create([
        'name' => 'legacy-stack',
        'service_type' => null,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  web:\n    image: httpd:2.4-alpine\n    environment:\n      - SERVICE_URL_WEB\n",
        'docker_compose' => Yaml::dump(['services' => ['web' => ['image' => 'httpd:2.4-alpine', 'labels' => ['traefik.http.services.web.loadbalancer.server.port=3000']]]]),
        'compose_parsing_version' => (string) $version,
    ]);
    ServiceApplication::create([
        'name' => 'web',
        'service_id' => $service->id,
        'image' => 'httpd:2.4-alpine',
        'fqdn' => 'https://legacy.example.com',
        'domain_port_overrides' => $override === null ? null : ['https://legacy.example.com' => $override],
    ]);

    for ($iteration = 0; $iteration < 2; $iteration++) {
        $parsed = $service->fresh()->parse();
        $labels = collect($parsed['services']['web']['labels']);
        $ports = $labels->filter(fn ($label) => str_contains($label, '.loadbalancer.server.port='));
        expect($ports)->not->toBeEmpty();
        foreach ($ports as $port) {
            expect($port)->toEndWith('='.($override ?? 3000));
        }
        expect($labels->filter(fn ($label) => str_contains($label, 'reverse_proxy'))->implode(' '))->toContain('{{upstreams '.($override ?? 3000).'}}');
        expect($service->fresh()->applications()->first()->fqdn)->toBe('https://legacy.example.com');
    }
})->with([4, 5])->with([null, 8080]);
