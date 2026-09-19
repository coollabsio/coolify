<?php

use App\Models\InfisicalConnection;
use App\Services\Infisical\InfisicalApiException;
use App\Services\Infisical\InfisicalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('it logs in and returns secrets as a key value map', function () {
    Http::fake([
        'https://infisical.test/api/v1/auth/universal-auth/login' => Http::response([
            'accessToken' => 'token-123',
        ]),
        'https://infisical.test/api/v3/secrets/raw*' => Http::response([
            'secrets' => [
                ['secretKey' => 'DB_PASSWORD', 'secretValue' => 'hunter2'],
                ['secretKey' => 'API_KEY', 'secretValue' => 'abc'],
            ],
        ]),
    ]);

    $connection = InfisicalConnection::factory()->create(['host' => 'https://infisical.test']);

    $secrets = (new InfisicalClient($connection))->fetchSecrets('proj-1', 'prod', '/');

    expect($secrets)->toBe(['DB_PASSWORD' => 'hunter2', 'API_KEY' => 'abc']);

    Http::assertSent(fn ($request) => $request->url() === 'https://infisical.test/api/v1/auth/universal-auth/login'
        && $request['clientId'] === $connection->client_id);
});

test('it sends the access token as a bearer credential on the secrets request', function () {
    Http::fake([
        'https://infisical.test/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'token-123']),
        'https://infisical.test/api/v3/secrets/raw*' => Http::response(['secrets' => []]),
    ]);

    $connection = InfisicalConnection::factory()->create(['host' => 'https://infisical.test']);
    (new InfisicalClient($connection))->fetchSecrets('proj-1', 'prod', '/');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v3/secrets/raw')
        && $request->hasHeader('Authorization', 'Bearer token-123'));
});

test('it throws when authentication fails', function () {
    Http::fake([
        'https://infisical.test/api/v1/auth/universal-auth/login' => Http::response(['message' => 'bad creds'], 401),
    ]);

    $connection = InfisicalConnection::factory()->create(['host' => 'https://infisical.test']);

    expect(fn () => (new InfisicalClient($connection))->fetchSecrets('proj-1', 'prod', '/'))
        ->toThrow(InfisicalApiException::class);
});

test('it throws when the secrets request fails', function () {
    Http::fake([
        'https://infisical.test/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'token-123']),
        'https://infisical.test/api/v3/secrets/raw*' => Http::response(['message' => 'nope'], 403),
    ]);

    $connection = InfisicalConnection::factory()->create(['host' => 'https://infisical.test']);

    expect(fn () => (new InfisicalClient($connection))->fetchSecrets('proj-1', 'prod', '/'))
        ->toThrow(InfisicalApiException::class);
});

test('the exception message never contains the client secret', function () {
    Http::fake([
        'https://infisical.test/api/v1/auth/universal-auth/login' => Http::response(['message' => 'bad creds'], 401),
    ]);

    $connection = InfisicalConnection::factory()->create([
        'host' => 'https://infisical.test',
        'client_secret' => 'super-secret-value',
    ]);

    try {
        (new InfisicalClient($connection))->fetchSecrets('proj-1', 'prod', '/');
        $this->fail('expected InfisicalApiException');
    } catch (InfisicalApiException $e) {
        expect($e->getMessage())->not->toContain('super-secret-value');
    }
});

test('it throws an InfisicalApiException when the login request cannot connect', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 7: Connection refused');
    });

    $connection = InfisicalConnection::factory()->create(['host' => 'https://infisical.test']);

    expect(fn () => (new InfisicalClient($connection))->fetchSecrets('proj-1', 'prod', '/'))
        ->toThrow(InfisicalApiException::class);
});

test('it throws an InfisicalApiException when the secrets request cannot connect', function () {
    Http::fake([
        'https://infisical.test/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'token-123']),
        'https://infisical.test/api/v3/secrets/raw*' => function () {
            throw new ConnectionException('cURL error 7: Connection refused');
        },
    ]);

    $connection = InfisicalConnection::factory()->create(['host' => 'https://infisical.test']);

    expect(fn () => (new InfisicalClient($connection))->fetchSecrets('proj-1', 'prod', '/'))
        ->toThrow(InfisicalApiException::class);
});

test('it throws when a secret value is hidden, naming the key but not any value', function () {
    Http::fake([
        'https://infisical.test/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'token-123']),
        'https://infisical.test/api/v3/secrets/raw*' => Http::response([
            'secrets' => [
                ['secretKey' => 'DB_PASSWORD', 'secretValue' => 'hunter2', 'secretValueHidden' => false],
                ['secretKey' => 'SUPER_SECRET', 'secretValue' => '', 'secretValueHidden' => true],
            ],
        ]),
    ]);

    $connection = InfisicalConnection::factory()->create(['host' => 'https://infisical.test']);

    try {
        (new InfisicalClient($connection))->fetchSecrets('proj-1', 'prod', '/');
        $this->fail('expected InfisicalApiException');
    } catch (InfisicalApiException $e) {
        expect($e->getMessage())->toContain('SUPER_SECRET')
            ->and($e->getMessage())->not->toContain('hunter2');
    }
});

test('it authenticates only once across multiple fetchSecrets calls', function () {
    Http::fake([
        'https://infisical.test/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'token-123']),
        'https://infisical.test/api/v3/secrets/raw*' => Http::response(['secrets' => []]),
    ]);

    $connection = InfisicalConnection::factory()->create(['host' => 'https://infisical.test']);
    $client = new InfisicalClient($connection);

    $client->fetchSecrets('proj-1', 'prod', '/');
    $client->fetchSecrets('proj-1', 'prod', '/');

    Http::assertSentCount(3);
});
