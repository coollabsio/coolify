<?php

use App\Rules\SafeWebhookUrl;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

it('accepts valid public URLs', function () {
    $rule = new SafeWebhookUrl;

    $validUrls = [
        'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXX',
        'https://discord.com/api/webhooks/123456/abcdef',
        'https://example.com/webhook',
        'http://example.com/webhook',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Expected valid: {$url}");
    }
});

it('accepts private network IPs for self-hosted deployments', function (string $url) {
    $rule = new SafeWebhookUrl;

    $validator = Validator::make(['url' => $url], ['url' => $rule]);
    expect($validator->passes())->toBeTrue("Expected valid (private IP): {$url}");
})->with([
    '10.x range' => 'http://10.0.0.5/webhook',
    '172.16.x range' => 'http://172.16.0.1:8080/hook',
    '192.168.x range' => 'http://192.168.1.50:8080/webhook',
]);

it('rejects loopback addresses', function (string $url) {
    $rule = new SafeWebhookUrl;

    $validator = Validator::make(['url' => $url], ['url' => $rule]);
    expect($validator->fails())->toBeTrue("Expected rejection: {$url}");
})->with([
    'loopback' => 'http://127.0.0.1',
    'loopback with port' => 'http://127.0.0.1:6379',
    'loopback /8 range' => 'http://127.0.0.2',
    'zero address' => 'http://0.0.0.0',
]);

it('rejects cloud metadata IP', function () {
    $rule = new SafeWebhookUrl;

    $validator = Validator::make(['url' => 'http://169.254.169.254/latest/meta-data/'], ['url' => $rule]);
    expect($validator->fails())->toBeTrue('Expected rejection: cloud metadata IP');
});

it('rejects link-local range', function () {
    $rule = new SafeWebhookUrl;

    $validator = Validator::make(['url' => 'http://169.254.0.1'], ['url' => $rule]);
    expect($validator->fails())->toBeTrue('Expected rejection: link-local IP');
});

it('rejects hostnames that resolve to blocked addresses', function (string $url, array $resolvedIps) {
    $rule = new SafeWebhookUrl(fn (string $host): array => $resolvedIps);

    $validator = Validator::make(['url' => $url], ['url' => $rule]);

    expect($validator->fails())->toBeTrue("Expected rejection after DNS resolution: {$url}");
})->with([
    'hostname to link-local IP' => ['http://169.254.169.254.nip.io/', ['169.254.169.254']],
    'hostname to loopback' => ['http://loopback.example.test/', ['127.0.0.1']],
    'hostname to IPv6 loopback' => ['http://ipv6-loopback.example.test/', ['::1']],
    'hostname to IPv6 link-local' => ['http://ipv6-link-local.example.test/', ['fe80::1']],
    'hostname to IPv6 ULA' => ['http://ipv6-ula.example.test/', ['fc00::1']],
    'hostname to mapped link-local IP' => ['http://mapped-link-local.example.test/', ['::ffff:169.254.169.254']],
]);

it('rejects IPv4-mapped IPv6 literals for blocked IPv4 ranges', function (string $url) {
    $rule = new SafeWebhookUrl;

    $validator = Validator::make(['url' => $url], ['url' => $rule]);

    expect($validator->fails())->toBeTrue("Expected rejection: {$url}");
})->with([
    'mapped link-local IP' => 'http://[::ffff:169.254.169.254]/',
    'mapped loopback' => 'http://[::ffff:127.0.0.1]/',
    'mapped zero' => 'http://[::ffff:0.0.0.0]/',
]);

it('rejects localhost and internal hostnames', function (string $url) {
    $rule = new SafeWebhookUrl;

    $validator = Validator::make(['url' => $url], ['url' => $rule]);
    expect($validator->fails())->toBeTrue("Expected rejection: {$url}");
})->with([
    'localhost' => 'http://localhost',
    'localhost with port' => 'http://localhost:8080',
    'localhost with trailing dot' => 'http://localhost.',
    '.internal domain' => 'http://myservice.internal',
    '.internal domain with trailing dot' => 'http://myservice.internal.',
]);

it('rejects non-http schemes', function (string $value) {
    $rule = new SafeWebhookUrl;

    $validator = Validator::make(['url' => $value], ['url' => $rule]);
    expect($validator->fails())->toBeTrue("Expected rejection: {$value}");
})->with([
    'ftp scheme' => 'ftp://example.com',
    'javascript scheme' => 'javascript:alert(1)',
    'file scheme' => 'file:///etc/passwd',
    'no scheme' => 'example.com',
]);

it('rejects IPv6 loopback', function () {
    $rule = new SafeWebhookUrl;

    $validator = Validator::make(['url' => 'http://[::1]'], ['url' => $rule]);
    expect($validator->fails())->toBeTrue('Expected rejection: IPv6 loopback');
});
