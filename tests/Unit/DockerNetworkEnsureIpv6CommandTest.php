<?php

use App\Models\Server;
use Symfony\Component\Process\Process;

function runDockerNetworkEnsureIpv6Script(
    array $commands,
    array $values,
    bool $useSudo = false,
): array {
    $script = <<<'BASH'
exec 3>&2
docker() {
    printf 'docker %s\n' "$*" >&3
    if [ "$FAIL_OPERATION" = "$1 $2" ]; then
        return 1
    fi
    if [ "$1" = "network" ] && [ "$2" = "inspect" ]; then
        target=""
        for argument in "$@"; do
            if [ "$argument" = "coolify" ]; then
                target="coolify"
            fi
        done
        if [ "$target" != "coolify" ]; then
            return 0
        fi
        if [ "$NETWORK_EXISTS" != "1" ]; then
            return 1
        fi
        case "$*" in
            *EnableIPv6*) printf '%s\n' "$ENABLE_IPV6" ;;
            *Containers*) printf '%b' "$CONTAINERS" ;;
        esac
        return 0
    fi
    if [ "$1" = "network" ] && [ "$2" = "create" ]; then
        ipv6=0
        last=""
        for argument in "$@"; do
            if [ "$argument" = "--ipv6" ]; then
                ipv6=1
            fi
            last="$argument"
        done
        if [ "$ipv6" -eq 1 ] && [ "$IPV6_AVAILABLE" != "1" ]; then
            return 1
        fi
        case "$last" in
            coolify-ipv6-probe-*) printf '%s\n' "probe-id" ;;
            *) printf '%s\n' "network-id" ;;
        esac
        return 0
    fi
    if [ "$1" = "network" ] && [ "$2" = "rm" ]; then
        return 0
    fi
    if [ "$1" = "network" ] && [ "$2" = "disconnect" ]; then
        return 0
    fi
    if [ "$1" = "network" ] && [ "$2" = "connect" ]; then
        return 0
    fi
    return 0
}
export -f docker
BASH;
    $settings = array_merge([
        'NETWORK_EXISTS' => '1',
        'ENABLE_IPV6' => 'false',
        'IPV6_AVAILABLE' => '1',
        'CONTAINERS' => "app\ncoolify-proxy\n",
        'FAIL_OPERATION' => '',
    ], $values);
    $script .= "\n".sprintf(
        "export NETWORK_EXISTS=%s\nexport ENABLE_IPV6=%s\nexport IPV6_AVAILABLE=%s\nexport CONTAINERS=%s\nexport FAIL_OPERATION=%s\n",
        escapeshellarg($settings['NETWORK_EXISTS']),
        escapeshellarg($settings['ENABLE_IPV6']),
        escapeshellarg($settings['IPV6_AVAILABLE']),
        escapeshellarg($settings['CONTAINERS']),
        escapeshellarg($settings['FAIL_OPERATION']),
    );

    if ($useSudo) {
        $script .= <<<'BASH'
sudo() {
    if [ "$1" = "bash" ] && [ "$2" = "-c" ]; then
        shift 2
        eval "$1"
    else
        "$@"
    fi
}
BASH;
    }
    $scriptPath = tempnam(sys_get_temp_dir(), 'coolify-network-');
    file_put_contents($scriptPath, $script."\n".implode("\n", $commands));
    $bashScriptPath = $scriptPath;
    if (PHP_OS_FAMILY === 'Windows') {
        $bashScriptPath = str_replace('\\', '/', strtolower($scriptPath));
        $bashScriptPath = preg_replace('#^([a-z]):/#', '/mnt/$1/', $bashScriptPath);
    }
    $process = new Process(['bash', $bashScriptPath]);
    $process->run();
    $output = $process->getErrorOutput();
    unlink($scriptPath);

    return [$process, $output];
}

function nonRootDockerNetworkEnsureIpv6Commands(): array
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->user = 'ubuntu';
    $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $server->shouldReceive('setAttribute')->andReturnSelf();

    $commands = parseCommandsByLineForSudo(
        collect(dockerNetworkEnsureIpv6Commands('coolify')),
        $server,
    );

    Mockery::close();

    return $commands;
}

it('reconciles an existing IPv4 network and reconnects its containers', function () {
    [$process, $output] = runDockerNetworkEnsureIpv6Script(
        dockerNetworkEnsureIpv6Commands('coolify'),
        [],
    );

    expect($process->isSuccessful())->toBeTrue()
        ->and($output)->toContain('network inspect')
        ->and($output)->toContain('network disconnect coolify app')
        ->and($output)->toContain('network disconnect coolify coolify-proxy')
        ->and($output)->toContain('network rm coolify')
        ->and($output)->toContain('network create --attachable --ipv6 coolify')
        ->and($output)->toContain('network connect coolify app')
        ->and($output)->toContain('network connect coolify coolify-proxy');
});

it('does not mutate an already-correct IPv6 network', function () {
    [$process, $output] = runDockerNetworkEnsureIpv6Script(
        dockerNetworkEnsureIpv6Commands('coolify'),
        ['ENABLE_IPV6' => 'true'],
    );

    expect($process->isSuccessful())->toBeTrue()
        ->and($output)->toContain('network inspect')
        ->not->toContain('network create')
        ->not->toContain('network rm')
        ->not->toContain('network disconnect')
        ->not->toContain('network connect');
});

it('preserves an existing IPv4 network when IPv6 is unavailable', function () {
    [$process, $output] = runDockerNetworkEnsureIpv6Script(
        dockerNetworkEnsureIpv6Commands('coolify'),
        ['IPV6_AVAILABLE' => '0'],
    );

    expect($process->isSuccessful())->toBeTrue()
        ->and($output)->toContain('network inspect')
        ->and($output)->not->toContain('network rm coolify')
        ->and($output)->not->toContain('network disconnect')
        ->and($output)->not->toContain('network connect');
});

it('runs IPv4 reconciliation through one sudo bash boundary', function () {
    $commands = nonRootDockerNetworkEnsureIpv6Commands();

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toStartWith("sudo bash -c '")
        ->not->toContain('$(sudo');

    [$process, $output] = runDockerNetworkEnsureIpv6Script($commands, [], true);

    expect($process->isSuccessful())->toBeTrue()
        ->and($output)->toContain('network disconnect coolify app')
        ->and($output)->toContain('network create --attachable --ipv6 coolify')
        ->and($output)->toContain('network connect coolify app');
});

it('keeps an already-correct IPv6 network unchanged through sudo', function () {
    [$process, $output] = runDockerNetworkEnsureIpv6Script(
        nonRootDockerNetworkEnsureIpv6Commands(),
        ['ENABLE_IPV6' => 'true'],
        true,
    );

    expect($process->isSuccessful())->toBeTrue()
        ->and($output)->toContain('network inspect')
        ->not->toContain('network create')
        ->not->toContain('network rm')
        ->not->toContain('network disconnect')
        ->not->toContain('network connect');
});

it('propagates failed Docker operations through the sudo boundary', function () {
    [$process, $output] = runDockerNetworkEnsureIpv6Script(
        nonRootDockerNetworkEnsureIpv6Commands(),
        ['FAIL_OPERATION' => 'network connect'],
        true,
    );

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getExitCode())->toBe(1)
        ->and($output)->toContain('network connect coolify app');
});

it('uses reconciliation commands from proxy bootstrap for standalone networks', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isSwarm')->andReturn(false);
    $server->standaloneDockers = collect([['network' => 'coolify']]);
    $server->swarmDockers = collect();
    $services = Mockery::mock();
    $services->shouldReceive('get')->andReturn(collect());
    $server->shouldReceive('services')->andReturn($services);
    $server->shouldReceive('dockerComposeBasedApplications')->andReturn(collect());
    $server->shouldReceive('dockerComposeBasedPreviewDeployments')->andReturn(collect());

    $commands = ensureProxyNetworksExist($server)->implode("\n");

    expect($commands)
        ->toContain('EnableIPv6')
        ->toContain('network disconnect')
        ->toContain('network create --attachable --ipv6');

    Mockery::close();
});
