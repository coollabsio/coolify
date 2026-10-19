<?php

/**
 * Runs ensure_coolify_network() from the upgrade script with a fake docker. Docker Engine 27.x reports an IPv6
 * gateway with its prefix length (fd00:1::1/64), which the Docker CLI and Compose in the helper image cannot parse.
 *
 * @return array{exitCode: int, calls: list<string>, status: string}
 */
function runUpgradeScriptEnsureCoolifyNetwork(string $script, ?string $existingGateways, string $createdIpv6Gateway): array
{
    $dir = sys_get_temp_dir().'/upgrade-network-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch("{$dir}/calls");
    touch("{$dir}/status");
    if ($existingGateways !== null) {
        file_put_contents("{$dir}/gateways", $existingGateways);
    }
    $source = file_get_contents(getcwd().'/'.$script);
    $functions = collect(['log', 'write_status', 'network_gateway_with_prefix', 'ensure_coolify_network'])
        ->map(function (string $name) use ($source): string {
            preg_match('/^'.$name.'\(\) \{.*?^\}\n/ms', $source, $match);

            return $match[0] ?? '';
        })
        ->implode('');

    $harness = <<<BASH
        LOGFILE="{$dir}/upgrade.log"
        STATUS_FILE="{$dir}/status"
        docker() {
            echo "\$*" >> "{$dir}/calls"
            case "\$1 \$2" in
                "network inspect")
                    [ -f "{$dir}/gateways" ] || { echo "Error: network coolify not found" >&2; return 1; }
                    cat "{$dir}/gateways"
                    ;;
                "network create")
                    case "\$*" in
                        *--ipv6*) echo "172.19.0.1 {$createdIpv6Gateway} " > "{$dir}/gateways" ;;
                        *) echo "172.19.0.1 " > "{$dir}/gateways" ;;
                    esac
                    ;;
                "network rm") rm -f "{$dir}/gateways" ;;
                version*) echo 27.5.1 ;;
            esac
        }
        {$functions}
        ensure_coolify_network
        BASH;

    exec('bash -c '.escapeshellarg($harness).' >/dev/null 2>&1', result_code: $exitCode);
    $calls = array_values(array_filter(explode("\n", file_get_contents("{$dir}/calls")), fn (string $call): bool => $call !== '' && ! str_starts_with($call, 'network inspect') && ! str_starts_with($call, 'version')));
    $status = file_get_contents("{$dir}/status");
    exec('rm -rf '.escapeshellarg($dir));

    return ['exitCode' => $exitCode, 'calls' => $calls, 'status' => $status];
}

it('stops the upgrade before any container changes when the coolify network has a gateway with a prefix length', function (string $script) {
    $result = runUpgradeScriptEnsureCoolifyNetwork($script, existingGateways: '172.19.0.1 fd00:1::1/64 ', createdIpv6Gateway: 'unused');

    expect($result['exitCode'])->toBe(1)
        ->and($result['calls'])->toBe([])
        ->and($result['status'])->toStartWith('error|')
        ->toContain('fd00:1::1/64')
        ->toContain('systemctl restart docker');
})->with(['scripts/upgrade.sh', 'other/nightly/upgrade.sh']);

it('keeps a readable coolify network', function (string $script) {
    $result = runUpgradeScriptEnsureCoolifyNetwork($script, existingGateways: '172.19.0.1 fd00:1::1 ', createdIpv6Gateway: 'unused');

    expect($result)->toBe(['exitCode' => 0, 'calls' => [], 'status' => '']);
})->with(['scripts/upgrade.sh', 'other/nightly/upgrade.sh']);

it('creates the coolify network with IPv6', function (string $script) {
    $result = runUpgradeScriptEnsureCoolifyNetwork($script, existingGateways: null, createdIpv6Gateway: 'fd00:1::1');

    expect($result)->toBe(['exitCode' => 0, 'calls' => ['network create --attachable --ipv6 coolify'], 'status' => '']);
})->with(['scripts/upgrade.sh', 'other/nightly/upgrade.sh']);

it('recreates a new coolify network without IPv6 when Docker reports its gateway with a prefix length', function (string $script) {
    $result = runUpgradeScriptEnsureCoolifyNetwork($script, existingGateways: null, createdIpv6Gateway: 'fd00:1::1/64');

    expect($result)->toBe([
        'exitCode' => 0,
        'calls' => [
            'network create --attachable --ipv6 coolify',
            'network rm coolify',
            'network create --attachable coolify',
        ],
        'status' => '',
    ]);
})->with(['scripts/upgrade.sh', 'other/nightly/upgrade.sh']);
