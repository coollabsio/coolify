<?php

use App\Models\Server;
use App\Support\ValidationPatterns;

function proxyNetworkTestServer(bool $swarm = false, array $serviceNetworks = [], ?array $destinationNetworks = null, bool $serviceRunning = false): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isSwarm')->andReturn($swarm);

    $destinationNetworks ??= $swarm ? ['coolify-overlay'] : ['coolify'];

    if ($swarm) {
        $server->swarmDockers = collect(array_map(fn (string $network) => ['network' => $network], $destinationNetworks));
        $server->standaloneDockers = collect();
    } else {
        $server->standaloneDockers = collect(array_map(fn (string $network) => ['network' => $network], $destinationNetworks));
        $server->swarmDockers = collect();
    }

    $services = collect($serviceNetworks)->map(function (array $networks) use ($serviceRunning) {
        $service = Mockery::mock();
        $service->shouldReceive('isRunning')->andReturn($serviceRunning);
        $service->shouldReceive('networks')->andReturn(collect($networks));

        return $service;
    });

    $relation = Mockery::mock();
    $relation->shouldReceive('get')->andReturn($services);
    $server->shouldReceive('services')->andReturn($relation);
    $server->shouldReceive('dockerComposeBasedApplications')->andReturn(collect());
    $server->shouldReceive('dockerComposeBasedPreviewDeployments')->andReturn(collect());

    return $server;
}

it('creates networks with inspect and a single escaped argument', function () {
    $safe = escapeshellarg('frontend');

    expect(dockerNetworkEnsureCommand('frontend'))
        ->toBe("docker network inspect {$safe} >/dev/null 2>&1 || docker network create --attachable {$safe}")
        ->and(dockerNetworkEnsureCommand('frontend', overlay: true, quietCreate: true))
        ->toBe("docker network inspect {$safe} >/dev/null 2>&1 || docker network create --driver overlay --attachable {$safe} >/dev/null");
});

it('keeps the inspect and create command structure when a network name contains quotes', function () {
    $name = "app'network";
    $safe = escapeshellarg($name);
    $command = dockerNetworkEnsureCommand($name);

    expect($command)
        ->toBe("docker network inspect {$safe} >/dev/null 2>&1 || docker network create --attachable {$safe}")
        ->not->toContain('| grep');
});

it('keeps only valid compose network names when collecting server networks', function () {
    $invalid = "app'network";
    $server = proxyNetworkTestServer(serviceNetworks: [[$invalid, 'frontend']]);

    ['allNetworks' => $allNetworks] = collectDockerNetworksByServer($server);

    expect($allNetworks->all())
        ->toContain('coolify')
        ->toContain('frontend')
        ->not->toContain($invalid);
});

it('builds proxy ensure commands with inspect and escaped create arguments', function () {
    $invalid = "app'network";
    $server = proxyNetworkTestServer(serviceNetworks: [[$invalid, 'frontend']]);

    $commands = ensureProxyNetworksExist($server)->implode("\n");
    $safeFrontend = escapeshellarg('frontend');

    expect($commands)
        ->toContain("docker network inspect {$safeFrontend} >/dev/null 2>&1 || docker network create --attachable {$safeFrontend}")
        ->not->toContain('| grep');
});

it('builds swarm proxy connect commands with inspect and escaped create arguments', function () {
    $invalid = "app'network";
    $server = proxyNetworkTestServer(swarm: true, serviceNetworks: [[$invalid, 'overlay-net']], serviceRunning: true);

    $commands = connectProxyToNetworks($server)->implode("\n");
    $safeOverlay = escapeshellarg('overlay-net');

    expect($commands)
        ->toContain("docker network inspect {$safeOverlay} >/dev/null 2>&1 || docker network create --driver overlay --attachable {$safeOverlay} >/dev/null")
        ->toContain("docker network connect {$safeOverlay} coolify-proxy")
        ->not->toContain('| grep');
});

it('rejects unusable docker network names', function (string $network) {
    expect(isUsableDockerNetworkName($network))->toBeFalse();
})->with([
    'quote' => "app'network",
    'semicolon' => 'net;id',
    'pipe' => 'net|id',
    'empty' => '',
]);

it('accepts usable docker network names', function (string $network) {
    expect(isUsableDockerNetworkName($network))->toBeTrue()
        ->and(ValidationPatterns::isValidDockerNetwork($network))->toBeTrue();
})->with([
    'simple' => 'frontend',
    'hyphen' => 'coolify-proxy',
    'uuid-like' => 'abcdefghijklmnopqrstuvwx',
]);
