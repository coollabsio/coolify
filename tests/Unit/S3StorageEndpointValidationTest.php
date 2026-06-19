<?php

use App\Models\S3Storage;
use App\Rules\SafeWebhookUrl;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Regression tests for SSRF via S3 Storage endpoint.
 *
 * The Livewire forms (Create.php, Form.php) and the model-level defense in
 * S3Storage::testConnection() share the same SafeWebhookUrl rule. These tests
 * assert the rule rejects the concrete payloads and that the model refuses to
 * build an S3 client for an unsafe endpoint.
 */
it('rejects SSRF payloads on the S3 endpoint', function (string $endpoint) {
    $validator = Validator::make(
        ['endpoint' => $endpoint],
        ['endpoint' => ['required', 'max:255', new SafeWebhookUrl]],
    );

    expect($validator->fails())->toBeTrue("Expected rejection: {$endpoint}");
})->with([
    'AWS IMDS' => 'http://169.254.169.254/latest/meta-data/',
    'AWS IMDS bare' => 'http://169.254.169.254',
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
        ['endpoint' => ['required', 'max:255', new SafeWebhookUrl]],
    );

    expect($validator->passes())->toBeTrue("Expected accepted: {$endpoint}");
})->with([
    'AWS S3' => 'https://s3.us-east-1.amazonaws.com',
    'Cloudflare R2' => 'https://fake.r2.cloudflarestorage.com',
    'DigitalOcean Spaces' => 'https://nyc3.digitaloceanspaces.com',
    'Backblaze B2' => 'https://s3.us-west-001.backblazeb2.com',
    'Self-hosted MinIO on 10.x' => 'http://10.0.0.5:9000',
    'Self-hosted MinIO on 172.16.x' => 'http://172.16.0.10:9000',
    'Self-hosted MinIO on 192.168.x' => 'http://192.168.1.50:9000',
    'Custom domain MinIO' => 'https://minio.example.com',
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
    'internal TLD' => 'http://backend.internal',
]);
