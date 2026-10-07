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

uses(RefreshDatabase::class);

it('does not infer a custom service port from a coincidental template name', function (int $version, string $name, ?string $type, string $declarations, ?int $expected) {
    expect(config('database.connections.'.config('database.default').'.driver'))->toBe('sqlite');
    expect(config('database.connections.'.config('database.default').'.database'))->toBe(':memory:');
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['generate_exact_labels' => false]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $service = Service::factory()->create([
        'name' => 'custom-stack',
        'service_type' => $type,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  {$name}:\n    image: httpd:2.4-alpine\n{$declarations}",
        'compose_parsing_version' => (string) $version,
    ]);
    ServiceApplication::create([
        'name' => $name,
        'service_id' => $service->id,
        'image' => 'httpd:2.4-alpine',
        'fqdn' => 'https://apache.example.com',
    ]);
    $parsed = $service->fresh()->parse();
    $labels = collect(data_get($parsed, 'services'))->flatMap(fn ($entry) => data_get($entry, 'labels', []));
    $ports = $labels->filter(fn ($label) => str_contains($label, '.loadbalancer.server.port='))->values()->all();
    if ($expected === null) {
        expect($ports)->toBeEmpty();
        expect($labels->filter(fn ($label) => str_contains($label, 'reverse_proxy'))->implode(' '))->not->toContain('upstreams 3000');
    } else {
        expect($ports)->not->toBeEmpty();
        foreach ($ports as $port) {
            expect($port)->toEndWith('.loadbalancer.server.port='.$expected);
        }
    }
})->with([4, 5])->with([
    'UDP before HTTP' => ['web', null, "    expose: ['53/udp', '8080/tcp']\n", 8080],
    'UDP only discovery' => ['web', null, "    expose: ['53/udp']\n", null],
    'custom collision' => ['grafana', null, "    expose: [80]\n", 80],
    'custom discovery' => ['grafana', null, '', null],
    'unrelated template child' => ['web', 'grafana', '', null],
    'real template' => ['wordpress', 'wordpress-without-database', "    environment:\n      - SERVICE_URL_WORDPRESS\n", 80],
    'explicit magic port' => ['web', null, "    expose: [80]\n    environment:\n      - SERVICE_URL_WEB_8080\n", 8080],
]);
