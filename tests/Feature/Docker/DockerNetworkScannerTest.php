<?php

use App\Enums\DockerNetworkRole;
use App\Enums\DockerNetworkSourceType;
use App\Models\DockerNetwork;
use App\Models\Server;
use App\Models\Team;
use App\Services\Docker\DockerNetworkScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\InteractsWithDockerNetworks;

uses(RefreshDatabase::class, InteractsWithDockerNetworks::class);

function fakeScanner(array $outputs): DockerNetworkScanner
{
    return new DockerNetworkScanner(executor: function (Server $server, array $command) use ($outputs) {
        foreach ($outputs as $needle => $output) {
            if (str_contains($command[0], $needle)) {
                return $output;
            }
        }

        return null;
    });
}

it('parses docker network ls output and ignores invalid lines', function () {
    $server = $this->createFunctionalServer();
    $scanner = fakeScanner([
        'docker network ls' => implode("\n", [
            json_encode(['Name' => 'bridge']),
            '',
            'not-json',
            json_encode(['Name' => 'coolify']),
        ]),
    ]);

    $networks = $scanner->list($server);

    expect($networks->pluck('docker_network_name')->all())->toBe(['bridge', 'coolify']);
});

it('returns empty array for invalid docker network inspect', function () {
    $server = $this->createFunctionalServer();
    $scanner = fakeScanner([
        "docker network inspect 'coolify'" => $this->dockerInspectPayload('coolify'),
        "docker network inspect 'missing'" => 'not-json',
    ]);

    expect($scanner->inspect($server, 'coolify')['docker_network_name'])->toBe('coolify')
        ->and($scanner->inspect($server, 'missing'))->toBe([]);
});

it('syncs docker networks and creates database records', function () {
    $server = $this->createFunctionalServer();
    $scanner = fakeScanner([
        'docker network ls' => implode("\n", [
            json_encode(['Name' => 'coolify']),
            json_encode(['Name' => 'external-net']),
        ]),
        "docker network inspect 'coolify'" => $this->dockerInspectPayload('coolify'),
        "docker network inspect 'external-net'" => $this->dockerInspectPayload('external-net'),
    ]);

    $result = $scanner->sync($server);

    expect($result->get('found'))->toBe(2)
        ->and($result->get('created'))->toBe(2)
        ->and(DockerNetwork::where('server_id', $server->id)->count())->toBe(2)
        ->and(DockerNetwork::where('docker_network_name', 'coolify')->first()->source_type)->toBe(DockerNetworkSourceType::StandaloneDockerDestination)
        ->and(DockerNetwork::where('docker_network_name', 'external-net')->first()->network_role)->toBe(DockerNetworkRole::SharedExternal);
});

it('updates existing records without overwriting custom display name or ownership', function () {
    $server = $this->createFunctionalServer();
    $this->createCatalogNetwork($server, 'coolify', [
        'display_name' => 'Custom Label',
        'managed_by_coolify' => true,
        'external' => false,
    ]);
    $scanner = fakeScanner([
        'docker network ls' => json_encode(['Name' => 'coolify']),
        "docker network inspect 'coolify'" => $this->dockerInspectPayload('coolify', [
            'Driver' => 'overlay',
            'Scope' => 'swarm',
        ]),
    ]);

    $result = $scanner->sync($server);
    $network = DockerNetwork::where('server_id', $server->id)->where('docker_network_name', 'coolify')->first();

    expect($result->get('created'))->toBe(0)
        ->and($result->get('updated'))->toBe(1)
        ->and($network->display_name)->toBe('Custom Label')
        ->and($network->managed_by_coolify)->toBeTrue()
        ->and($network->driver->value)->toBe('overlay');
});

it('marks missing active networks inactive and remains idempotent', function () {
    $server = $this->createFunctionalServer();
    DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Missing',
        'docker_network_name' => 'missing-net',
        'is_active' => true,
    ]);
    $scanner = fakeScanner([
        'docker network ls' => json_encode(['Name' => 'coolify']),
        "docker network inspect 'coolify'" => $this->dockerInspectPayload('coolify'),
    ]);

    $first = $scanner->sync($server);
    $second = $scanner->sync($server);

    expect($first->get('marked_inactive'))->toBe(1)
        ->and($second->get('created'))->toBe(0)
        ->and(DockerNetwork::where('docker_network_name', 'missing-net')->first()->is_active)->toBeFalse();
});

it('returns controlled error when server is not functional', function () {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $scanner = fakeScanner([]);

    $result = $scanner->sync($server);

    expect($result->get('errors'))->toBe(['Server is not functional.']);
});

it('does not mark networks inactive when docker network listing fails', function () {
    $server = $this->createFunctionalServer();
    $existing = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Existing',
        'docker_network_name' => 'existing-net',
        'is_active' => true,
    ]);
    $scanner = new DockerNetworkScanner(executor: fn () => null);

    $result = $scanner->sync($server);

    expect($result->get('errors'))->toBe(['Unable to list Docker networks.'])
        ->and($existing->refresh()->is_active)->toBeTrue();
});
