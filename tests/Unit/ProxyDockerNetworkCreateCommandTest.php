<?php

use App\Models\Server;

function mockProxyNetworkServer(bool $isSwarm = false, array $standaloneDockers = [], array $swarmDockers = []): Server
{
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('isSwarm')->andReturn($isSwarm);
    $server->shouldReceive('getSchemalessAttributes')->andReturn([]);
    $server->shouldReceive('getAttribute')->with('standaloneDockers')->andReturn(collect($standaloneDockers));
    $server->shouldReceive('getAttribute')->with('swarmDockers')->andReturn(collect($swarmDockers));

    return $server;
}

it('can prefer ipv6 for standalone proxy networks while keeping a plain fallback', function () {
    $command = dockerNetworkCreateCommand('coolify', preferIpv6: true);

    expect($command)->toBe("(docker network create --ipv6 --attachable 'coolify' 2>/dev/null || docker network create --attachable 'coolify')");
});

it('keeps plain standalone network creation when ipv6 is not requested', function () {
    $command = dockerNetworkCreateCommand('app-network');

    expect($command)->toBe("docker network create --attachable 'app-network'");
});

it('prefers ipv6 only for standalone networks used by the proxy compose file', function () {
    $server = mockProxyNetworkServer(standaloneDockers: [
        ['network' => 'coolify'],
    ]);

    expect(shouldCreateProxyNetworkWithIpv6($server, 'coolify'))->toBeTrue()
        ->and(shouldCreateProxyNetworkWithIpv6($server, 'application-uuid'))->toBeFalse();
});

it('uses the default coolify network as the standalone proxy network when no destinations exist', function () {
    $server = mockProxyNetworkServer();

    expect(shouldCreateProxyNetworkWithIpv6($server, 'coolify'))->toBeTrue()
        ->and(shouldCreateProxyNetworkWithIpv6($server, 'preview-network'))->toBeFalse();
});

it('does not request ipv6 for swarm overlay proxy networks', function () {
    $server = mockProxyNetworkServer(isSwarm: true, swarmDockers: [
        ['network' => 'coolify-overlay'],
    ]);

    expect(shouldCreateProxyNetworkWithIpv6($server, 'coolify-overlay'))->toBeFalse();

    $command = dockerNetworkCreateCommand('coolify-overlay', isSwarm: true, preferIpv6: true);

    expect($command)
        ->toBe("docker network create --driver overlay --attachable 'coolify-overlay'")
        ->not->toContain('--ipv6');
});
