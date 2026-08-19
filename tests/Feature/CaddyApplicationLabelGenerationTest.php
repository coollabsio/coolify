<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('generates exact Caddy labels for an application with a domain', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::CADDY->value],
    ]);
    $server->settings->update(['generate_exact_labels' => true]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $application = Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://example.com',
        'redirect' => 'both',
        'is_http_basic_auth_enabled' => false,
    ]);

    $labels = generateLabelsApplication($application);

    expect($labels)
        ->toContain('caddy_ingress_network=coolify')
        ->not->toContain('traefik.enable=true');
});
