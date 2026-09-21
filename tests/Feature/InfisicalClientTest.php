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

    expect($secrets->values)->toBe(['DB_PASSWORD' => 'hunter2', 'API_KEY' => 'abc']);

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

test('a truthy but non-boolean secretValueHidden still hides the value', function () {
    Http::fake([
        'https://infisical.test/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'token-123']),
        'https://infisical.test/api/v3/secrets/raw*' => Http::response([
            'secrets' => [
                ['secretKey' => 'SUPER_SECRET', 'secretValue' => '', 'secretValueHidden' => 1],
            ],
        ]),
    ]);

    $connection = InfisicalConnection::factory()->create(['host' => 'https://infisical.test']);

    $secrets = (new InfisicalClient($connection))->fetchSecrets('proj-1', 'prod', '/');

    expect($secrets->hiddenKeys)->toBe(['SUPER_SECRET'])
        ->and($secrets->values)->toBe([]);
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

it('reports hidden secrets instead of throwing', function () {
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'tok']),
        '*/api/v3/secrets/raw*' => Http::response(['secrets' => [
            ['secretKey' => 'VISIBLE', 'secretValue' => 'yes', 'secretValueHidden' => false],
            ['secretKey' => 'MASKED', 'secretValue' => '', 'secretValueHidden' => true],
        ]]),
    ]);

    $result = (new InfisicalClient(InfisicalConnection::factory()->create()))
        ->fetchSecrets('proj', 'production', '/');

    expect($result->values)->toBe(['VISIBLE' => 'yes'])
        ->and($result->hiddenKeys)->toBe(['MASKED']);
});

it('reads environment slugs from the project object', function () {
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'tok']),
        '*/api/v1/workspace/proj' => Http::response(['workspace' => ['environments' => [
            ['id' => '1', 'name' => 'Production', 'slug' => 'production'],
            ['id' => '2', 'name' => 'Staging', 'slug' => 'staging'],
        ]]]),
    ]);

    expect((new InfisicalClient(InfisicalConnection::factory()->create()))->listEnvironmentSlugs('proj'))
        ->toBe(['production', 'staging']);
});

it('returns false when environment creation is refused', function () {
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'tok']),
        '*/api/v1/workspace/proj/environments' => Http::response(['message' => 'forbidden'], 403),
    ]);

    expect((new InfisicalClient(InfisicalConnection::factory()->create()))
        ->createEnvironment('proj', 'UAT', 'uat'))->toBeFalse();
});

it('creates each folder level separately because the api does not recurse', function () {
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'tok']),
        '*/api/v1/folders?*' => Http::response(['folders' => []]),
        '*/api/v1/folders' => Http::response(['folder' => ['id' => 'f']], 200),
    ]);

    (new InfisicalClient(InfisicalConnection::factory()->create()))
        ->ensureFolderPath('proj', 'production', '/shop-api/api-server/');

    $creates = collect(Http::recorded())
        ->filter(fn ($pair) => $pair[0]->method() === 'POST'
            && str_contains($pair[0]->url(), '/api/v1/folders'))
        ->map(fn ($pair) => [$pair[0]['path'], $pair[0]['name']])
        ->values()
        ->all();

    expect($creates)->toBe([
        ['/', 'shop-api'],
        ['/shop-api', 'api-server'],
    ]);
});

it('skips creating a folder level that already exists', function () {
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'tok']),
        '*/api/v1/folders?*' => Http::response(['folders' => [['name' => 'shop-api']]]),
        '*/api/v1/folders' => Http::response(['folder' => ['id' => 'f']], 200),
    ]);

    (new InfisicalClient(InfisicalConnection::factory()->create()))
        ->ensureFolderPath('proj', 'production', '/shop-api/');

    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/api/v1/folders'));
});

it('upserts secrets in one batch call', function () {
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'tok']),
        '*/api/v3/secrets/batch/raw' => Http::response(['secrets' => []]),
    ]);

    (new InfisicalClient(InfisicalConnection::factory()->create()))
        ->upsertSecrets('proj', 'production', '/shop-api/', ['A' => '1', 'B' => '2']);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/api/v3/secrets/batch/raw')
        && $request['mode'] === 'upsert'
        && $request['workspaceId'] === 'proj'
        && $request['environment'] === 'production'
        && $request['secretPath'] === '/shop-api/'
        && $request['secrets'] === [
            ['secretKey' => 'A', 'secretValue' => '1'],
            ['secretKey' => 'B', 'secretValue' => '2'],
        ]);
});

it('does not call the api at all when there is nothing to upsert', function () {
    Http::fake(['*' => Http::response([])]);

    (new InfisicalClient(InfisicalConnection::factory()->create()))
        ->upsertSecrets('proj', 'production', '/', []);

    Http::assertNothingSent();
});
