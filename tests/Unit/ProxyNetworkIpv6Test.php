<?php

use App\Models\Server;

/**
 * Runs the commands with -e (like the remote shell) and a fake docker that keeps the network state in files.
 *
 * @param  list<string>  $commands
 * @return array{exitCode: int, calls: list<string>}
 */
function runProxyNetworkCommandsWithFakeDocker(array $commands, bool $exists = false, bool $ipv6Available = true, string $ipv6Gateway = 'fd00:1::1'): array
{
    $dir = sys_get_temp_dir().'/proxy-network-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch("{$dir}/calls");
    touch("{$dir}/gateway");
    if ($exists) {
        touch("{$dir}/exists");
    }
    $ipv6 = $ipv6Available ? '1' : '0';
    file_put_contents("{$dir}/docker", <<<SH
        #!/bin/sh
        case "\$1 \$2" in
            "network inspect")
                [ -f "{$dir}/exists" ] || exit 1
                case "\$*" in *Gateway*) echo "172.18.0.1 \$(cat "{$dir}/gateway") " ;; esac
                ;;
            "network create")
                echo "\$*" >> "{$dir}/calls"
                case "\$*" in
                    *--ipv6*) [ "{$ipv6}" = 1 ] || exit 1; echo "{$ipv6Gateway}" > "{$dir}/gateway" ;;
                    *) : > "{$dir}/gateway" ;;
                esac
                touch "{$dir}/exists"
                ;;
            "network rm")
                echo "\$*" >> "{$dir}/calls"
                rm -f "{$dir}/exists"
                ;;
        esac
        SH);
    file_put_contents("{$dir}/sudo", "#!/bin/sh\nexec \"\$@\"\n");
    chmod("{$dir}/docker", 0755);
    chmod("{$dir}/sudo", 0755);

    exec('PATH='.escapeshellarg($dir).':"$PATH" sh -ec '.escapeshellarg(implode("\n", $commands)).' >/dev/null 2>&1', result_code: $exitCode);
    $calls = array_values(array_filter(explode("\n", file_get_contents("{$dir}/calls"))));
    exec('rm -rf '.escapeshellarg($dir));

    return ['exitCode' => $exitCode, 'calls' => $calls];
}

it('creates the proxy network with IPv6', function () {
    expect(runProxyNetworkCommandsWithFakeDocker([dockerProxyNetworkEnsureCommand('coolify')]))->toBe([
        'exitCode' => 0,
        'calls' => ['network create --attachable --ipv6 coolify'],
    ]);
});

it('creates the proxy network without IPv6 when Docker cannot allocate an IPv6 subnet', function () {
    expect(runProxyNetworkCommandsWithFakeDocker([dockerProxyNetworkEnsureCommand('coolify')], ipv6Available: false))->toBe([
        'exitCode' => 0,
        'calls' => [
            'network create --attachable --ipv6 coolify',
            'network create --attachable coolify',
        ],
    ]);
});

it('recreates the proxy network without IPv6 when Docker reports its IPv6 gateway with a prefix length', function () {
    expect(runProxyNetworkCommandsWithFakeDocker([dockerProxyNetworkEnsureCommand('coolify')], ipv6Gateway: 'fd00:1::1/64'))->toBe([
        'exitCode' => 0,
        'calls' => [
            'network create --attachable --ipv6 coolify',
            'network rm coolify',
            'network create --attachable coolify',
        ],
    ]);
});

it('does not change an existing proxy network', function () {
    expect(runProxyNetworkCommandsWithFakeDocker([dockerProxyNetworkEnsureCommand('coolify')], exists: true))->toBe([
        'exitCode' => 0,
        'calls' => [],
    ]);
});

it('passes the proxy network name as one argument', function () {
    $marker = sys_get_temp_dir().'/proxy-network-injected-'.bin2hex(random_bytes(4));
    $network = "net'; touch {$marker}; '";

    $result = runProxyNetworkCommandsWithFakeDocker([dockerProxyNetworkEnsureCommand($network)]);

    expect($result['calls'])->toBe(["network create --attachable --ipv6 {$network}"])
        ->and(file_exists($marker))->toBeFalse();
});

it('runs the proxy network script as one sudo call for non-root users', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $command = dockerProxyNetworkEnsureCommand('coolify');

    $commands = parseCommandsByLineForSudo(collect([$command]), $server);
    $line = parseLineForSudo($command, $server);

    expect($commands)->toBe(["sudo {$command}"])
        ->and($line)->toBe("sudo {$command}")
        ->and(runProxyNetworkCommandsWithFakeDocker($commands)['calls'])->toBe(['network create --attachable --ipv6 coolify']);
});
