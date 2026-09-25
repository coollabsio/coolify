<?php

use App\Models\InstanceSettings;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    config(['constants.coolify.self_hosted' => true]);
});

function gitSourceOptions(string $url, array $resolvedIps = ['203.0.113.10']): array
{
    return SafeExternalUrl::httpClientOptions($url, resolver: fn (string $host): array => $resolvedIps, allowPrivateNetworks: true);
}

function gitSourceUrlIsValid(string $url): bool
{
    return Validator::make(['api_url' => $url], ['api_url' => [SafeExternalUrl::forGitSource()]])->passes();
}

test('self-hosted Git sources can use private networks and internal hostnames', function (string $url, array $resolvedIps) {
    $options = gitSourceOptions($url, $resolvedIps);

    expect($options['allow_redirects'])->toBeFalse();
})->with([
    'RFC 1918 IP' => ['https://10.0.0.5/api/v3', []],
    'private IP with port' => ['http://192.168.1.20:8929/api/v4', []],
    'Tailscale CGNAT IP' => ['https://100.101.102.103/api/v4', []],
    'IPv6 unique local address' => ['https://[fd12:3456::1]/api/v4', []],
    'hostname on a private IP' => ['https://github.company.lan/api/v3', ['10.1.2.3']],
    '.internal hostname' => ['https://gitlab.corp.internal/api/v4', ['172.16.5.4']],
    '.local hostname' => ['https://gitea.local/api/v1', ['192.168.1.30']],
    'single-label container name' => ['http://gitea:3000/api/v1', ['172.18.0.7']],
    'Tailscale MagicDNS name' => ['https://git.tail1234.ts.net/api/v4', ['100.64.0.9']],
]);

test('the private-network mode pins the resolved private address', function () {
    $options = gitSourceOptions('https://gitlab.corp.internal/api/v4', ['172.16.5.4']);

    expect($options['curl'][CURLOPT_RESOLVE][0])->toBe('gitlab.corp.internal:443:172.16.5.4');
});

test('Git sources still cannot reach metadata, loopback, or reserved targets', function (string $url, array $resolvedIps) {
    expect(fn () => gitSourceOptions($url, $resolvedIps))->toThrow(RuntimeException::class);
})->with([
    'cloud metadata IP' => ['http://169.254.169.254/latest', []],
    'metadata hostname' => ['http://metadata.google.internal/computeMetadata', ['169.254.169.254']],
    'IPv6 link-local' => ['http://[fe80::1]/', []],
    'loopback IP' => ['http://127.0.0.1:6379/', []],
    'IPv6 loopback' => ['http://[::1]/', []],
    'localhost' => ['http://localhost:8080/', []],
    'hostname on loopback' => ['https://git.example.com/', ['127.0.0.1']],
    'unspecified address' => ['http://0.0.0.0/', []],
    'IPv4-mapped metadata IP' => ['http://[::ffff:169.254.169.254]/', []],
    'multicast' => ['http://224.0.0.1/', []],
    'hostname with one private and one metadata IP' => ['https://git.example.com/', ['10.0.0.5', '169.254.169.254']],
]);

test('other outbound URLs still block private networks by default', function () {
    expect(fn () => SafeExternalUrl::httpClientOptions('https://10.0.0.5/hook'))
        ->toThrow(RuntimeException::class, 'unsafe IP address')
        ->and(fn () => SafeExternalUrl::httpClientOptions('https://gitlab.corp.internal/', resolver: fn (): array => ['172.16.5.4']))
        ->toThrow(RuntimeException::class);
});

test('the Git source HTTP client allows a private GitHub Enterprise on self-hosted', function () {
    $options = Http::GitHub('https://10.20.30.40/api/v3', 'secret')->getOptions();

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['headers']['Authorization'])->toBe('Bearer secret');
});

test('the Git source HTTP client keeps private networks blocked on Coolify Cloud', function () {
    config(['constants.coolify.self_hosted' => false]);

    expect(fn () => Http::GitHub('https://10.20.30.40/api/v3', 'secret'))
        ->toThrow(RuntimeException::class, 'unsafe IP address');
});

test('Git source URL validation follows the same private-network rules', function () {
    expect(gitSourceUrlIsValid('https://10.20.30.40/api/v3'))->toBeTrue()
        ->and(gitSourceUrlIsValid('https://100.101.102.103/api/v4'))->toBeTrue()
        ->and(gitSourceUrlIsValid('http://169.254.169.254/latest'))->toBeFalse()
        ->and(gitSourceUrlIsValid('http://127.0.0.1/'))->toBeFalse()
        ->and(gitSourceUrlIsValid('http://localhost/'))->toBeFalse();

    config(['constants.coolify.self_hosted' => false]);

    expect(gitSourceUrlIsValid('https://10.20.30.40/api/v3'))->toBeFalse();
});
