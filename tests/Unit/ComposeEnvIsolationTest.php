<?php

/**
 * Unit tests for stripSharedEnvFileFromComposeServices().
 *
 * Verifies that Docker Compose services do not receive the shared project-wide
 * `.env` file, which would expose every service's secrets to every container.
 *
 * @see https://github.com/coollabsio/coolify/issues/7655
 */
it('removes the shared project .env from every service so secrets are not leaked across containers', function () {
    $composeFile = [
        'services' => [
            'app' => [
                'image' => 'node',
                'environment' => ['OPENAI_API_KEY' => '${OPENAI_API_KEY}'],
                'env_file' => ['.env'],
            ],
            'db' => [
                'image' => 'postgres',
                'environment' => ['POSTGRES_PASSWORD' => '${POSTGRES_PASSWORD}'],
                'env_file' => ['.env'],
            ],
            'redis' => [
                'image' => 'redis',
                'env_file' => ['.env'],
            ],
        ],
    ];

    $result = stripSharedEnvFileFromComposeServices($composeFile);

    foreach ($result['services'] as $service) {
        expect(data_get($service, 'env_file', []))->not->toContain('.env');
    }

    // Each service still only declares its own variables.
    expect(array_keys($result['services']['app']['environment']))->toBe(['OPENAI_API_KEY']);
    expect(array_keys($result['services']['db']['environment']))->toBe(['POSTGRES_PASSWORD']);
});

it('preserves a service-specific env_file while dropping the shared one', function () {
    $composeFile = [
        'services' => [
            'app' => ['env_file' => ['./app.env', '.env']],
        ],
    ];

    $result = stripSharedEnvFileFromComposeServices($composeFile);

    expect($result['services']['app']['env_file'])->toBe(['./app.env']);
});

it('unsets env_file entirely when only the shared .env was present', function () {
    $composeFile = [
        'services' => [
            'app' => ['image' => 'node', 'env_file' => ['.env']],
        ],
    ];

    $result = stripSharedEnvFileFromComposeServices($composeFile);

    expect(data_get($result, 'services.app.env_file'))->toBeNull();
    expect(data_get($result, 'services.app.image'))->toBe('node');
});

it('handles a string env_file value', function () {
    $composeFile = [
        'services' => [
            'app' => ['env_file' => '.env'],
        ],
    ];

    $result = stripSharedEnvFileFromComposeServices($composeFile);

    expect(data_get($result, 'services.app.env_file'))->toBeNull();
});

it('is a no-op for services without any env_file', function () {
    $composeFile = [
        'services' => [
            'app' => ['image' => 'node', 'environment' => ['FOO' => 'bar']],
        ],
    ];

    $result = stripSharedEnvFileFromComposeServices($composeFile);

    expect($result['services']['app'])->toBe(['image' => 'node', 'environment' => ['FOO' => 'bar']]);
});
