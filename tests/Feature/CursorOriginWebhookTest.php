<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function cursorOriginWebhookRequest(array $payload, string $eventType = 'repository.pushed'): array
{
    $body = json_encode([
        'deliveryId' => 'whd_test',
        'appId' => 'app_test',
        'installationId' => 'i_test',
        'event' => ['id' => 'evt_test', 'type' => $eventType, 'eventTime' => now()->toRfc3339String(), 'payload' => $payload],
    ], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $digest = hash('sha256', "whd_test.{$timestamp}.{$body}");
    $signature = sodium_crypto_sign_detached($digest, sodium_crypto_sign_secretkey($keyPair));

    Cache::forget('cursor-origin-jwks');
    Http::fake(['https://api.cursor.com/v1/origin/keys' => Http::response(['keys' => [[
        'kty' => 'OKP', 'crv' => 'Ed25519', 'use' => 'sig', 'alg' => 'EdDSA',
        'x' => rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '='),
    ]]])]);

    return [$body, [
        'HTTP_WEBHOOK-ID' => 'whd_test',
        'HTTP_WEBHOOK-TIMESTAMP' => $timestamp,
        'HTTP_WEBHOOK-SIGNATURE' => 'v1ed,'.base64_encode($signature),
        'CONTENT_TYPE' => 'application/json',
    ]];
}

test('cursor origin webhook rejects unsigned requests', function () {
    $this->postJson('/webhooks/source/cursor/events', [])->assertUnauthorized();
});

test('cursor origin webhook accepts a valid signed unsupported event', function () {
    [$body, $headers] = cursorOriginWebhookRequest([], 'installation.created');

    $this->call('POST', '/webhooks/source/cursor/events', [], [], [], $headers, $body)
        ->assertOk()
        ->assertSee('not supported');
});

test('cursor origin webhook accepts signed repository pushes', function () {
    [$body, $headers] = cursorOriginWebhookRequest([
        'repository' => ['name' => 'example', 'owner' => ['slug' => 'acme']],
        'refUpdates' => [[
            'ref' => 'refs/heads/main',
            'before' => str_repeat('0', 40),
            'after' => str_repeat('a', 40),
        ]],
    ]);

    $this->call('POST', '/webhooks/source/cursor/events', [], [], [], $headers, $body)
        ->assertOk()
        ->assertJsonPath('message', 'Nothing to do. No matching branch updates or applications found.');
});

test('cursor origin webhook rejects stale signatures', function () {
    [$body, $headers] = cursorOriginWebhookRequest([]);
    $headers['HTTP_WEBHOOK-TIMESTAMP'] = (string) now()->subMinutes(6)->timestamp;

    $this->call('POST', '/webhooks/source/cursor/events', [], [], [], $headers, $body)
        ->assertUnauthorized();
});

test('cursor origin webhook rejects malformed signatures', function () {
    [$body, $headers] = cursorOriginWebhookRequest([]);
    $headers['HTTP_WEBHOOK-SIGNATURE'] = 'v1ed,'.base64_encode('invalid');

    $this->call('POST', '/webhooks/source/cursor/events', [], [], [], $headers, $body)
        ->assertUnauthorized();
});
