<?php

use App\Models\Server;
use App\Models\ServerSetting;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    // Mock Log facade to prevent actual logging during tests
    Log::shouldReceive('debug')->andReturn(null);
    Log::shouldReceive('error')->andReturn(null);
});

it('parses traefik version with v prefix', function () {
    $image = 'traefik:v3.6';
    preg_match('/traefik:(v?\d+\.\d+(?:\.\d+)?|latest)/i', $image, $matches);

    expect($matches[1])->toBe('v3.6');
});

it('parses traefik version without v prefix', function () {
    $image = 'traefik:3.6.0';
    preg_match('/traefik:(v?\d+\.\d+(?:\.\d+)?|latest)/i', $image, $matches);

    expect($matches[1])->toBe('3.6.0');
});

it('parses traefik latest tag', function () {
    $image = 'traefik:latest';
    preg_match('/traefik:(v?\d+\.\d+(?:\.\d+)?|latest)/i', $image, $matches);

    expect($matches[1])->toBe('latest');
});

it('parses traefik version with patch number', function () {
    $image = 'traefik:v3.5.1';
    preg_match('/traefik:(v?\d+\.\d+(?:\.\d+)?|latest)/i', $image, $matches);

    expect($matches[1])->toBe('v3.5.1');
});

it('parses traefik version with minor only', function () {
    $image = 'traefik:3.6';
    preg_match('/traefik:(v?\d+\.\d+(?:\.\d+)?|latest)/i', $image, $matches);

    expect($matches[1])->toBe('3.6');
});

it('returns null for invalid image format', function () {
    $image = 'nginx:latest';
    preg_match('/traefik:(v?\d+\.\d+(?:\.\d+)?|latest)/i', $image, $matches);

    expect($matches)->toBeEmpty();
});

it('returns null for empty image string', function () {
    $image = '';
    preg_match('/traefik:(v?\d+\.\d+(?:\.\d+)?|latest)/i', $image, $matches);

    expect($matches)->toBeEmpty();
});

it('handles case insensitive traefik image name', function () {
    $image = 'TRAEFIK:v3.6';
    preg_match('/traefik:(v?\d+\.\d+(?:\.\d+)?|latest)/i', $image, $matches);

    expect($matches[1])->toBe('v3.6');
});

it('parses full docker image with registry', function () {
    $image = 'docker.io/library/traefik:v3.6';
    preg_match('/traefik:(v?\d+\.\d+(?:\.\d+)?|latest)/i', $image, $matches);

    expect($matches[1])->toBe('v3.6');
});

it('compares versions correctly after stripping v prefix', function () {
    $version1 = 'v3.5';
    $version2 = 'v3.6';

    $result = version_compare(ltrim($version1, 'v'), ltrim($version2, 'v'), '<');

    expect($result)->toBeTrue();
});

it('compares same versions as equal', function () {
    $version1 = 'v3.6';
    $version2 = '3.6';

    $result = version_compare(ltrim($version1, 'v'), ltrim($version2, 'v'), '=');

    expect($result)->toBeTrue();
});

it('compares versions with patch numbers', function () {
    $version1 = '3.5.1';
    $version2 = '3.6.0';

    $result = version_compare($version1, $version2, '<');

    expect($result)->toBeTrue();
});

it('parses exact version from traefik version command output', function () {
    $output = "Version:      3.6.0\nCodename:     ramequin\nGo version:   go1.24.10";
    preg_match('/Version:\s+(\d+\.\d+\.\d+)/', $output, $matches);

    expect($matches[1])->toBe('3.6.0');
});

it('parses exact version from OCI label with v prefix', function () {
    $label = 'v3.6.0';
    preg_match('/(\d+\.\d+\.\d+)/', $label, $matches);

    expect($matches[1])->toBe('3.6.0');
});

it('parses exact version from OCI label without v prefix', function () {
    $label = '3.6.0';
    preg_match('/(\d+\.\d+\.\d+)/', $label, $matches);

    expect($matches[1])->toBe('3.6.0');
});

it('extracts major.minor branch from full version', function () {
    $version = '3.6.0';
    preg_match('/^(\d+\.\d+)\.(\d+)$/', $version, $matches);

    expect($matches[1])->toBe('3.6'); // branch
    expect($matches[2])->toBe('0');   // patch
});

it('compares patch versions within same branch', function () {
    $current = '3.6.0';
    $latest = '3.6.2';

    $result = version_compare($current, $latest, '<');

    expect($result)->toBeTrue();
});

it('detects up-to-date patch version', function () {
    $current = '3.6.2';
    $latest = '3.6.2';

    $result = version_compare($current, $latest, '=');

    expect($result)->toBeTrue();
});

it('compares branches for minor upgrades', function () {
    $currentBranch = '3.5';
    $newerBranch = '3.6';

    $result = version_compare($currentBranch, $newerBranch, '<');

    expect($result)->toBeTrue();
});

it('identifies default as predefined network', function () {
    expect(isDockerPredefinedNetwork('default'))->toBeTrue();
});

it('identifies host as predefined network', function () {
    expect(isDockerPredefinedNetwork('host'))->toBeTrue();
});

it('identifies coolify as not predefined network', function () {
    expect(isDockerPredefinedNetwork('coolify'))->toBeFalse();
});

it('identifies coolify-overlay as not predefined network', function () {
    expect(isDockerPredefinedNetwork('coolify-overlay'))->toBeFalse();
});

it('identifies custom networks as not predefined', function () {
    $customNetworks = ['my-network', 'app-network', 'custom-123'];

    foreach ($customNetworks as $network) {
        expect(isDockerPredefinedNetwork($network))->toBeFalse();
    }
});

it('identifies bridge as not predefined (per codebase pattern)', function () {
    // 'bridge' is technically a Docker predefined network, but existing codebase
    // only filters 'default' and 'host', so we maintain consistency
    expect(isDockerPredefinedNetwork('bridge'))->toBeFalse();
});

it('identifies none as not predefined (per codebase pattern)', function () {
    // 'none' is technically a Docker predefined network, but existing codebase
    // only filters 'default' and 'host', so we maintain consistency
    expect(isDockerPredefinedNetwork('none'))->toBeFalse();
});

it('creates standalone proxy networks with ipv6 when requested', function () {
    $command = dockerNetworkCreateCommand('coolify', isSwarm: false, enableIpv6: true);

    expect($command)
        ->toContain('docker network create')
        ->toContain('--attachable')
        ->toContain('--ipv6')
        ->toContain('||')
        ->toContain('coolify');
});

it('keeps standalone proxy network creation unchanged when ipv6 is not requested', function () {
    $command = dockerNetworkCreateCommand('coolify', isSwarm: false, enableIpv6: false);

    expect($command)
        ->toContain('docker network create')
        ->toContain('--attachable')
        ->not->toContain('--ipv6')
        ->toContain('coolify');
});

it('creates swarm proxy networks with ipv6 when requested', function () {
    $command = dockerNetworkCreateCommand('coolify-overlay', isSwarm: true, enableIpv6: true);

    expect($command)
        ->toContain('docker network create')
        ->toContain('--driver overlay')
        ->toContain('--attachable')
        ->toContain('--ipv6')
        ->toContain('coolify-overlay');
});

it('enables docker network ipv6 for ipv6 server addresses', function () {
    $server = new Server(['ip' => '2001:db8::10']);
    $server->setRelation('settings', new ServerSetting);

    expect(shouldEnableDockerNetworkIpv6($server))->toBeTrue();
});

it('enables docker network ipv6 when the local instance has a public ipv6 address', function () {
    $server = new Server(['ip' => '192.0.2.10']);
    $server->id = 0;
    $server->setRelation('settings', new ServerSetting);

    expect(shouldEnableDockerNetworkIpv6($server, '2001:db8::20'))->toBeTrue();
});
