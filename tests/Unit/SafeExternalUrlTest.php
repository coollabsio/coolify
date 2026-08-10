<?php

use App\Rules\SafeExternalUrl;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

it('accepts valid public URLs', function () {
    $rule = new SafeExternalUrl;

    $validUrls = [
        'https://api.github.com',
        'https://github.com/api/v3',
        'https://example.com',
        'http://example.com',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Expected valid: {$url}");
    }
});

it('accepts custom external hostnames that resolve to public IPs', function () {
    $rule = new SafeExternalUrl(fn (string $host): array => ['93.184.216.34']);

    $validator = Validator::make(['url' => 'https://github.example.com/api/v3'], ['url' => $rule]);

    expect($validator->passes())->toBeTrue('Expected valid custom external hostname');
});

it('rejects private IPv4 addresses', function (string $url) {
    $rule = new SafeExternalUrl;

    $validator = Validator::make(['url' => $url], ['url' => $rule]);
    expect($validator->fails())->toBeTrue("Expected rejection: {$url}");
})->with([
    'loopback' => 'http://127.0.0.1',
    'loopback with port' => 'http://127.0.0.1:6379',
    '10.x range' => 'http://10.0.0.1',
    '172.16.x range' => 'http://172.16.0.1',
    '192.168.x range' => 'http://192.168.1.1',
]);

it('rejects cloud metadata IP', function () {
    $rule = new SafeExternalUrl;

    $validator = Validator::make(['url' => 'http://169.254.169.254'], ['url' => $rule]);
    expect($validator->fails())->toBeTrue('Expected rejection: cloud metadata IP');
});

it('rejects hostnames that resolve to private or reserved addresses', function (string $url, array $resolvedIps) {
    $rule = new SafeExternalUrl(fn (string $host): array => $resolvedIps);

    $validator = Validator::make(['url' => $url], ['url' => $rule]);

    expect($validator->fails())->toBeTrue("Expected rejection after DNS resolution: {$url}");
})->with([
    'hostname to link-local IP' => ['http://169.254.169.254.nip.io/', ['169.254.169.254']],
    'hostname to loopback' => ['http://loopback.example.test/', ['127.0.0.1']],
    'hostname to private IPv4' => ['http://private.example.test/', ['10.0.0.1']],
    'hostname to IPv6 loopback' => ['http://ipv6-loopback.example.test/', ['::1']],
    'hostname to IPv6 link-local' => ['http://ipv6-link-local.example.test/', ['fe80::1']],
    'hostname to IPv6 ULA' => ['http://ipv6-ula.example.test/', ['fc00::1']],
    'hostname to mapped private IPv4' => ['http://mapped-private.example.test/', ['::ffff:10.0.0.1']],
]);

it('rejects IPv4-mapped IPv6 literals for private or reserved IPv4 ranges', function (string $url) {
    $rule = new SafeExternalUrl;

    $validator = Validator::make(['url' => $url], ['url' => $rule]);

    expect($validator->fails())->toBeTrue("Expected rejection: {$url}");
})->with([
    'mapped link-local IP' => 'http://[::ffff:169.254.169.254]/',
    'mapped loopback' => 'http://[::ffff:127.0.0.1]/',
    'mapped private' => 'http://[::ffff:10.0.0.1]/',
]);

it('rejects localhost and internal hostnames', function (string $url) {
    $rule = new SafeExternalUrl;

    $validator = Validator::make(['url' => $url], ['url' => $rule]);
    expect($validator->fails())->toBeTrue("Expected rejection: {$url}");
})->with([
    'localhost' => 'http://localhost',
    'localhost with port' => 'http://localhost:8080',
    'localhost with trailing dot' => 'http://localhost.',
    'zero address' => 'http://0.0.0.0',
    '.local domain' => 'http://myservice.local',
    '.local domain with trailing dot' => 'http://myservice.local.',
    '.internal domain' => 'http://myservice.internal',
    '.internal domain with trailing dot' => 'http://myservice.internal.',
]);

it('rejects non-URL strings', function (string $value) {
    $rule = new SafeExternalUrl;

    $validator = Validator::make(['url' => $value], ['url' => $rule]);
    expect($validator->fails())->toBeTrue("Expected rejection: {$value}");
})->with([
    'plain string' => 'not-a-url',
    'ftp scheme' => 'ftp://example.com',
    'javascript scheme' => 'javascript:alert(1)',
    'no scheme' => 'example.com',
]);

it('rejects URLs with IPv6 loopback', function () {
    $rule = new SafeExternalUrl;

    $validator = Validator::make(['url' => 'http://[::1]'], ['url' => $rule]);
    expect($validator->fails())->toBeTrue('Expected rejection: IPv6 loopback');
});
