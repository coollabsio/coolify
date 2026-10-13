<?php

use App\Services\DopplerService;
use App\Services\InfisicalService;
use App\Services\VaultService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

describe('DopplerService', function () {
    test('downloads secrets as a flat key value map', function () {
        Http::fake([
            'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
                'DATABASE_URL' => 'postgres://user:pass@host/db',
                'API_KEY' => 'secret-value',
            ]),
        ]);

        $secrets = (new DopplerService('dp.st.test'))->fetchSecrets();

        expect($secrets)->toBe([
            'DATABASE_URL' => 'postgres://user:pass@host/db',
            'API_KEY' => 'secret-value',
        ]);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer dp.st.test')
            && str_contains($request->url(), 'format=json')
            && ! str_contains($request->url(), 'project='));
    });

    test('sends project and config for service account tokens', function () {
        Http::fake([
            'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response(['KEY' => 'value']),
        ]);

        (new DopplerService('dp.sa.test'))->fetchSecrets('my-project', 'prd');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'project=my-project')
            && str_contains($request->url(), 'config=prd'));
    });

    test('throws a readable error when the download fails', function () {
        Http::fake([
            'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
                'messages' => ['Invalid Auth token'],
            ], 401),
        ]);

        expect(fn () => (new DopplerService('bad-token'))->fetchSecrets())
            ->toThrow(RuntimeException::class, 'Doppler API error: Invalid Auth token');
    });

    test('validates the token against the me endpoint', function () {
        Http::fake([
            'https://api.doppler.com/v3/me' => Http::response(['type' => 'service_token']),
        ]);

        expect((new DopplerService('dp.st.test'))->validate())->toBeTrue();
    });

    test('validation fails for a rejected token', function () {
        Http::fake([
            'https://api.doppler.com/v3/me' => Http::response([], 401),
        ]);

        expect((new DopplerService('bad'))->validate())->toBeFalse();
    });
});

describe('InfisicalService', function () {
    test('rejects an unapproved endpoint before sending credentials', function () {
        Http::fake();

        expect(fn () => new InfisicalService('http://127.0.0.1:8080', 'client-id', 'client-secret'))
            ->toThrow(ValidationException::class);

        Http::assertNothingSent();
    });

    test('logs in with universal auth and fetches secrets from the v4 endpoint', function () {
        Http::fake([
            'https://example.com/infisical/api/v1/auth/universal-auth/login' => Http::response([
                'accessToken' => 'short-lived-token',
            ]),
            'https://example.com/infisical/api/v4/secrets*' => Http::response([
                'secrets' => [
                    ['secretKey' => 'DB_PASSWORD', 'secretValue' => 's3cret'],
                    ['secretKey' => 'API_KEY', 'secretValue' => 'abc'],
                ],
            ]),
        ]);

        $service = new InfisicalService('https://example.com/infisical/', 'client-id', 'client-secret');
        $secrets = $service->fetchSecrets('project-1', 'prod', '/');

        expect($secrets)->toBe([
            'DB_PASSWORD' => 's3cret',
            'API_KEY' => 'abc',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v4/secrets')
            && $request->hasHeader('Authorization', 'Bearer short-lived-token')
            && str_contains($request->url(), 'projectId=project-1'));
    });

    test('falls back to the v3 raw endpoint on older self-hosted instances', function () {
        Http::fake([
            'https://example.com/infisical/api/v1/auth/universal-auth/login' => Http::response([
                'accessToken' => 'short-lived-token',
            ]),
            'https://example.com/infisical/api/v4/secrets*' => Http::response([], 404),
            'https://example.com/infisical/api/v3/secrets/raw*' => Http::response([
                'secrets' => [
                    ['secretKey' => 'LEGACY_KEY', 'secretValue' => 'legacy-value'],
                ],
            ]),
        ]);

        $service = new InfisicalService('https://example.com/infisical', 'client-id', 'client-secret');

        expect($service->fetchSecrets('project-1', 'prod'))->toBe(['LEGACY_KEY' => 'legacy-value']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'workspaceId=project-1'));
    });

    test('throws when the login fails', function () {
        Http::fake([
            'https://example.com/infisical/api/v1/auth/universal-auth/login' => Http::response([
                'message' => 'Invalid credentials',
            ], 401),
        ]);

        $service = new InfisicalService('https://example.com/infisical', 'client-id', 'wrong');

        expect($service->validate())->toBeFalse()
            ->and(fn () => $service->fetchSecrets('project-1', 'prod'))
            ->toThrow(RuntimeException::class, 'Infisical login failed: Invalid credentials');
    });
});

describe('VaultService', function () {
    test('rejects an unapproved endpoint before sending the token', function () {
        Http::fake();

        expect(fn () => new VaultService('http://127.0.0.1:8200', 'hvs.token'))
            ->toThrow(ValidationException::class);

        Http::assertNothingSent();
    });

    test('reads a kv v2 secret and stringifies non-string values', function () {
        Http::fake([
            'https://example.com:8200/vault/v1/secret/data/my-app/production' => Http::response([
                'data' => [
                    'data' => [
                        'DB_PASSWORD' => 's3cret',
                        'REPLICAS' => 3,
                    ],
                ],
            ]),
        ]);

        $secrets = (new VaultService('https://example.com:8200/vault/', 'hvs.token'))
            ->fetchSecrets('secret', '/my-app/production/');

        expect($secrets)->toBe([
            'DB_PASSWORD' => 's3cret',
            'REPLICAS' => '3',
        ]);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Vault-Token', 'hvs.token')
            && ! $request->hasHeader('X-Vault-Namespace'));
    });

    test('sends the namespace header when configured', function () {
        Http::fake([
            'https://example.com:8200/vault/v1/secret/data/my-app' => Http::response([
                'data' => ['data' => ['KEY' => 'value']],
            ]),
        ]);

        (new VaultService('https://example.com:8200/vault', 'hvs.token', 'admin/team-a'))
            ->fetchSecrets('secret', 'my-app');

        Http::assertSent(fn ($request) => $request->hasHeader('X-Vault-Namespace', 'admin/team-a'));
    });

    test('throws a readable error when the read fails', function () {
        Http::fake([
            'https://example.com:8200/vault/v1/secret/data/missing' => Http::response([
                'errors' => ['permission denied'],
            ], 403),
        ]);

        expect(fn () => (new VaultService('https://example.com:8200/vault', 'hvs.token'))->fetchSecrets('secret', 'missing'))
            ->toThrow(RuntimeException::class, 'Vault API error: permission denied');
    });

    test('validates the token with lookup-self', function () {
        Http::fake([
            'https://example.com:8200/vault/v1/auth/token/lookup-self' => Http::response(['data' => []]),
        ]);

        expect((new VaultService('https://example.com:8200/vault', 'hvs.token'))->validate())->toBeTrue();
    });
});
