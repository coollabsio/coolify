<?php

it('builds standalone docker network create commands with ipv6 fallback', function () {
    $safeNetwork = escapeshellarg('coolify');
    $inspect = "docker network inspect {$safeNetwork} >/dev/null 2>&1";
    $ipv6Enabled = "[ \"$(docker network inspect --format '{{.EnableIPv6}}' {$safeNetwork} 2>/dev/null)\" = \"true\" ]";
    $ipv6Warning = "echo 'Existing Docker network does not have IPv6 enabled; recreate it to preserve IPv6 client IPs.' >&2";

    expect(dockerNetworkCreateCommand('coolify'))->toBe(
        "{$inspect} && ({$ipv6Enabled} || {$ipv6Warning}) || docker network create --attachable --ipv6 {$safeNetwork} >/dev/null 2>&1 || {$inspect} || docker network create --attachable {$safeNetwork}"
    );
});

it('can silence standalone docker network create commands', function () {
    $safeNetwork = escapeshellarg('coolify');
    $inspect = "docker network inspect {$safeNetwork} >/dev/null 2>&1";

    expect(dockerNetworkCreateCommand('coolify', quiet: true))->toBe(
        "{$inspect} || docker network create --attachable --ipv6 {$safeNetwork} >/dev/null 2>&1 || {$inspect} || docker network create --attachable {$safeNetwork} >/dev/null 2>&1"
    );
});

it('keeps swarm overlay docker network creation unchanged', function () {
    $safeNetwork = escapeshellarg('coolify-overlay');
    $inspect = "docker network inspect {$safeNetwork} >/dev/null 2>&1";

    expect(dockerNetworkCreateCommand('coolify-overlay', isSwarm: true))->toBe(
        "{$inspect} || docker network create --driver overlay --attachable {$safeNetwork}"
    )->not->toContain('--ipv6');
});

it('shell escapes docker network names in create commands', function () {
    $network = "app's.network";
    $safeNetwork = escapeshellarg($network);

    expect(dockerNetworkCreateCommand($network))
        ->toContain("docker network inspect {$safeNetwork}")
        ->toContain("docker network create --attachable --ipv6 {$safeNetwork}")
        ->toContain("docker network create --attachable {$safeNetwork}");
});
