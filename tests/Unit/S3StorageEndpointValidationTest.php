<?php

use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Rules\SafeWebhookUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Regression tests for S3 Storage endpoint validation.
 *
 * The Livewire forms (Create.php, Form.php) and the model-level defense in
 * S3Storage::testConnection() share the same SafeWebhookUrl rule. These tests
 * assert the rule rejects the concrete payloads and that the model refuses to
 * build an S3 client for an unsafe endpoint.
 */
it('rejects disallowed targets on the S3 endpoint', function (string $endpoint) {
    $validator = Validator::make(
        ['endpoint' => $endpoint],
        ['endpoint' => ['required', 'max:255', new SafeWebhookUrl]],
    );

    expect($validator->fails())->toBeTrue("Expected rejection: {$endpoint}");
})->with([
    'link-local address' => 'http://169.254.169.254/',
    'link-local address bare' => 'http://169.254.169.254',
    'link-local address IPv4-mapped IPv6' => 'http://[::ffff:169.254.169.254]/',
    'GCP metadata via link-local' => 'http://169.254.0.1',
    'loopback v4' => 'http://127.0.0.1',
    'loopback Redis' => 'http://127.0.0.1:6379',
    'loopback Postgres' => 'http://127.0.0.1:5432',
    'loopback alt in /8' => 'http://127.10.20.30',
    'zero address' => 'http://0.0.0.0',
    'IPv6 loopback' => 'http://[::1]',
    'localhost hostname' => 'http://localhost',
    'localhost with port' => 'http://localhost:9000',
    'internal suffix' => 'http://minio.internal',
    'file scheme' => 'file:///etc/passwd',
    'javascript scheme' => 'javascript:alert(1)',
]);

it('accepts real-world S3 endpoints', function (string $endpoint) {
    $validator = Validator::make(
        ['endpoint' => $endpoint],
        ['endpoint' => ['required', 'max:255', new SafeWebhookUrl(fn (string $host): array => ['93.184.216.34'])]],
    );

    expect($validator->passes())->toBeTrue("Expected accepted: {$endpoint}");
})->with([
    'AWS S3' => 'https://s3.us-east-1.amazonaws.com',
    'DigitalOcean Spaces' => 'https://nyc3.digitaloceanspaces.com',
    'Backblaze B2' => 'https://s3.us-west-001.backblazeb2.com',
    'Custom public domain S3-compatible endpoint' => 'https://example.com',
]);

it('blocks testConnection() on an unsafe endpoint without issuing HTTP', function () {
    $s3Storage = new S3Storage;
    $s3Storage->setRawAttributes([
        'region' => 'us-east-1',
        'key' => 'AKIAEXAMPLE',
        'secret' => 'secret',
        'bucket' => 'latest/meta-data',
        'endpoint' => 'http://169.254.169.254',
    ]);

    expect(fn () => $s3Storage->testConnection())
        ->toThrow(RuntimeException::class, 'S3 endpoint is not allowed');
});

it('blocks testConnection() for loopback endpoints', function (string $endpoint) {
    $s3Storage = new S3Storage;
    $s3Storage->setRawAttributes([
        'region' => 'us-east-1',
        'key' => 'AKIAEXAMPLE',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => $endpoint,
    ]);

    expect(fn () => $s3Storage->testConnection())
        ->toThrow(RuntimeException::class, 'S3 endpoint is not allowed');
})->with([
    'http loopback' => 'http://127.0.0.1:6379',
    'localhost' => 'http://localhost:9000',
    'IPv6 loopback' => 'http://[::1]',
    'IPv4-mapped IPv6 link-local' => 'http://[::ffff:169.254.169.254]',
    'internal TLD' => 'http://backend.internal',
]);

it('accepts explicitly allowlisted intranet S3 endpoints', function (string $endpoint, array $allowlist) {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['webhook_allowed_internal_hosts' => $allowlist]));

    $validator = Validator::make(
        ['endpoint' => $endpoint],
        ['endpoint' => ['required', 'max:255', new SafeWebhookUrl]],
    );

    expect($validator->passes())->toBeTrue("Expected allowlisted intranet S3 endpoint: {$endpoint}");
})->with([
    'Self-hosted MinIO on 10.x CIDR' => ['http://10.0.0.5:9000', ['10.0.0.0/8']],
    'Self-hosted MinIO on 172.16.x CIDR' => ['http://172.16.0.10:9000', ['172.16.0.0/12']],
    'Self-hosted MinIO on 192.168.x exact IP' => ['http://192.168.1.50:9000', ['192.168.1.50']],
]);
