<?php

use App\Models\InstanceSettings;
use App\Rules\SafeWebhookUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('accepts valid public URLs', function () {
    $rule = new SafeWebhookUrl(fn (string $host): array => ['93.184.216.34']);

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

it('rejects private and reserved network targets by default', function (string $url) {
    $rule = new SafeWebhookUrl;

    $validator = Validator::make(['url' => $url], ['url' => $rule]);

    expect($validator->fails())->toBeTrue("Expected default rejection: {$url}");
})->with([
    'private 10/8' => 'http://10.0.0.5/webhook',
    'private 172.16/12' => 'http://172.16.0.1:8080/hook',
    'private 192.168/16' => 'http://192.168.1.50:8080/webhook',
    'shared address space' => 'http://100.64.0.1/webhook',
    'zero network peer alias' => 'http://0.0.0.1/webhook',
    'multicast' => 'http://224.0.0.1/webhook',
    'benchmark range' => 'http://198.18.0.1/webhook',
    'documentation range' => 'http://192.0.2.10/webhook',
]);

it('rejects hostname forms that resolve to loopback', function (string $url) {
    $rule = new SafeWebhookUrl;

    $validator = Validator::make(['url' => $url], ['url' => $rule]);

    expect($validator->fails())->toBeTrue("Expected loopback hostname-form rejection: {$url}");
})->with([
    'decimal IPv4' => 'http://2130706433:8888/exfil',
    'hex IPv4' => 'http://0x7f000001:8888/exfil',
    'octal IPv4' => 'http://017700000001:8888/exfil',
    'short dotted IPv4' => 'http://127.1:8888/exfil',
    'IPv4-mapped IPv6 hex loopback' => 'http://[::ffff:7f00:1]:8888/exfil',
]);

it('rejects internal DNS suffixes by default', function (string $url) {
    $rule = new SafeWebhookUrl(fn (string $host): array => ['93.184.216.34']);

    $validator = Validator::make(['url' => $url], ['url' => $rule]);

    expect($validator->fails())->toBeTrue("Expected default rejection: {$url}");
})->with([
    '.local host' => 'http://receiver.local/webhook',
    '.cluster.local host' => 'http://service.cluster.local/webhook',
]);

it('rejects unresolvable hostnames by default', function () {
    $rule = new SafeWebhookUrl(fn (string $host): array => []);

    $validator = Validator::make(['url' => 'http://does-not-resolve.example.test/webhook'], ['url' => $rule]);

    expect($validator->fails())->toBeTrue('Expected default rejection for unresolvable host');
});

it('keeps webhook DNS resolution enabled when general DNS validation is disabled', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['is_dns_validation_enabled' => false]));

    $rule = new SafeWebhookUrl(fn (string $host): array => ['127.0.0.1']);

    $validator = Validator::make(['url' => 'http://rebinding.example.test/webhook'], ['url' => $rule]);

    expect($validator->fails())->toBeTrue('Expected webhook SSRF DNS checks to remain enabled');
});

it('reads configured custom DNS servers for webhook hostname resolution', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['custom_dns_servers' => '1.1.1.1, invalid, 2606:4700:4700::1111']));

    $method = new ReflectionMethod(SafeWebhookUrl::class, 'customDnsServers');
    $method->setAccessible(true);

    expect($method->invoke(new SafeWebhookUrl))
        ->toBe(['1.1.1.1', '2606:4700:4700::1111']);
});

it('allows explicitly configured intranet webhook targets', function (string $url, array $resolvedIps, array $allowlist) {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['webhook_allowed_internal_hosts' => $allowlist]));

    $rule = new SafeWebhookUrl(fn (string $host): array => $resolvedIps);

    $validator = Validator::make(['url' => $url], ['url' => $rule]);

    expect($validator->passes())->toBeTrue("Expected configured intranet target to pass: {$url}");
})->with([
    'exact .local hostname' => ['http://receiver.local/webhook', ['192.168.10.20'], ['receiver.local']],
    'private CIDR' => ['http://hooks.example.test/webhook', ['10.50.10.20'], ['10.50.0.0/16']],
]);

it('requires explicit localhost opt in in addition to allowlist', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['webhook_allowed_internal_hosts' => ['localhost']]));

    $rule = new SafeWebhookUrl;

    $validator = Validator::make(['url' => 'http://localhost:8080/webhook'], ['url' => $rule]);

    expect($validator->fails())->toBeTrue('Expected localhost to remain blocked without explicit localhost opt in');

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['webhook_allow_localhost' => true]));

    $validator = Validator::make(['url' => 'http://localhost:8080/webhook'], ['url' => $rule]);

    expect($validator->passes())->toBeTrue('Expected localhost to pass only after explicit localhost opt in');
});

it('builds HTTP client options that pin resolved DNS for the request', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], [
        'webhook_allowed_internal_hosts' => ['localhost'],
        'webhook_allow_localhost' => true,
    ]));

    $options = SafeWebhookUrl::httpClientOptions('http://localhost:8080/webhook');

    expect($options['allow_redirects'])->toBeFalse();

    if (defined('CURLOPT_RESOLVE')) {
        expect($options['curl'][CURLOPT_RESOLVE])->toContain('localhost:8080:127.0.0.1');
    }
});

it('fails closed while building HTTP options when the send-time resolution is unsafe', function () {
    expect(fn () => SafeWebhookUrl::httpClientOptions('http://localhost:8080/webhook'))
        ->toThrow(RuntimeException::class, 'unsafe IP address');
});

it('builds MinIO client resolve options for S3 backup uploads', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], [
        'webhook_allowed_internal_hosts' => ['localhost'],
        'webhook_allow_localhost' => true,
    ]));

    $options = SafeWebhookUrl::minioClientResolveOptions('http://localhost:9000');

    expect($options)->toContain('localhost:9000=127.0.0.1');
});

it('rejects trailing-dot hostnames to avoid DNS pinning mismatch', function () {
    $rule = new SafeWebhookUrl(fn (string $host): array => ['93.184.216.34']);

    $validator = Validator::make(['url' => 'http://example.com./webhook'], ['url' => $rule]);

    expect($validator->fails())->toBeTrue('Expected trailing-dot hostname rejection');

    expect(fn () => SafeWebhookUrl::httpClientOptions('http://example.com./webhook'))
        ->toThrow(RuntimeException::class, 'trailing dot');
});

it('rejects reserved IPv6 ranges by default', function (string $url) {
    $rule = new SafeWebhookUrl;

    $validator = Validator::make(['url' => $url], ['url' => $rule]);

    expect($validator->fails())->toBeTrue("Expected reserved IPv6 rejection: {$url}");
})->with([
    'documentation IPv6' => 'http://[2001:db8::1]/webhook',
    'IPv4/IPv6 translation prefix' => 'http://[64:ff9b::1]/webhook',
    '6to4' => 'http://[2002::1]/webhook',
]);

it('rejects hostnames that resolve to reserved IPv6 ranges by default', function (string $resolvedIp) {
    $rule = new SafeWebhookUrl(fn (string $host): array => [$resolvedIp]);

    $validator = Validator::make(['url' => 'http://ipv6-reserved.example.test/webhook'], ['url' => $rule]);

    expect($validator->fails())->toBeTrue("Expected reserved IPv6 resolution rejection: {$resolvedIp}");
})->with([
    '2001:db8::1',
    '64:ff9b::1',
    '2002::1',
]);

it('redacts webhook URLs for logs', function () {
    expect(SafeWebhookUrl::redactedUrlForLog('https://hooks.slack.com/services/T000/B000/secret-token?foo=bar'))
        ->toBe('https://hooks.slack.com');
});
