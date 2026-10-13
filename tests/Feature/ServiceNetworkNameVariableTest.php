<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeServiceWithNetworks(string $networks): Service
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    return Service::factory()->create([
        'server_id' => $server->id,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n    networks:\n      - shared\n      - other\n      - plain\nnetworks:\n{$networks}",
    ]);
}

test('variables in network names become service variables with their default', function () {
    $service = makeServiceWithNetworks(<<<'YAML'
  shared:
    external: true
    name: ${SHARED_NETWORK:-traefik_public}
  other:
    external: true
    name: ${OTHER_NETWORK}
  plain:
    name: fixed-network
YAML);

    $service->parse();

    $variables = $service->environment_variables()->pluck('value', 'key');

    expect($variables->get('SHARED_NETWORK'))->toBe('traefik_public')
        ->and($variables->has('OTHER_NETWORK'))->toBeTrue()
        ->and((string) $variables->get('OTHER_NETWORK'))->toBe('')
        ->and($variables->keys()->filter(fn ($key) => str_contains($key, 'fixed') || str_contains($key, 'plain')))->toBeEmpty()
        // Compose resolves the name from .env at deployment, so the compose file keeps the variable.
        ->and($service->fresh()->docker_compose)->toContain('${SHARED_NETWORK:-traefik_public}');
});

test('a changed network variable value is kept when the compose file is parsed again', function () {
    $service = makeServiceWithNetworks(<<<'YAML'
  shared:
    external: true
    name: ${SHARED_NETWORK-traefik-public}
  other:
    name: other-network
  plain:
    name: plain-network
YAML);

    $service->parse();
    expect($service->environment_variables()->where('key', 'SHARED_NETWORK')->value('value'))->toBe('traefik-public');

    $service->environment_variables()->where('key', 'SHARED_NETWORK')->firstOrFail()->update(['value' => 'my_shared']);
    $service->fresh()->parse();

    expect($service->environment_variables()->where('key', 'SHARED_NETWORK')->count())->toBe(1)
        ->and($service->environment_variables()->where('key', 'SHARED_NETWORK')->value('value'))->toBe('my_shared');
});
