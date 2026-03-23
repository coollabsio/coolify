<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Tests for environment variable isolation in Docker Compose deployments.
 *
 * Verifies the fix for #7655: previously, a shared .env file containing ALL
 * environment variables was injected into every container via env_file,
 * meaning any container could read secrets meant for other containers.
 *
 * The fix: stop injecting env_file: ['.env']. Each service's environment:
 * section already contains only its own variables (set by the parser).
 */

// -- Parser: no longer injects .env into env_file --

it('does not inject shared .env into service env_file', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // The old code pushed '.env' into every service's env_file.
    // After fix: no parser function should push '.env' into env_file.
    expect($parsersFile)->not->toContain("->push('.env')",
        'No parser should inject .env into env_file — environment: section handles per-service vars'
    );
});

it('preserves user-defined env_file entries except .env', function () {
    // Simulate the new parser logic for env_file handling
    $testCases = [
        // User has custom env files — they should be preserved
        [
            'input' => ['./secrets.env', './shared.env'],
            'expected' => ['./secrets.env', './shared.env'],
        ],
        // User had .env explicitly — it should be stripped
        [
            'input' => ['.env', './custom.env'],
            'expected' => ['./custom.env'],
        ],
        // User had only .env — env_file should be omitted entirely
        [
            'input' => ['.env'],
            'expected' => [],
        ],
        // No env_file defined — nothing added
        [
            'input' => null,
            'expected' => null,
        ],
        // String format (single file)
        [
            'input_raw' => './my.env',
            'expected' => ['./my.env'],
        ],
    ];

    foreach ($testCases as $i => $case) {
        $existingEnvFiles = $case['input_raw'] ?? $case['input'];
        $expected = $case['expected'];

        // Replicate the exact logic from parsers.php
        if (! is_null($existingEnvFiles)) {
            $envFiles = collect(is_array($existingEnvFiles) ? $existingEnvFiles : [$existingEnvFiles])
                ->reject(fn ($f) => $f === '.env')
                ->unique()
                ->values();
            $result = $envFiles->isNotEmpty() ? $envFiles->toArray() : [];
        } else {
            $result = null;
        }

        if ($expected === null) {
            expect($result)->toBeNull("Case {$i}: null input should produce null output");
        } else {
            expect($result)->toBe($expected, "Case {$i}: env_file filtering failed");
        }
    }
});

// -- Deployment job: no longer overwrites env_file --

it('does not overwrite service env_file in deployment job', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    // The old code had: $service['env_file'] = ['.env'];
    // inside a map() over compose services. Verify this pattern is gone
    // (but still exists for single-container dockerimage at line ~2762, which is fine).
    $lines = explode("\n", $jobFile);
    $composeEnvFileInjections = 0;

    foreach ($lines as $lineNum => $line) {
        // Match the pattern: env_file'] = ['.env'] in a map/foreach over services
        if (str_contains($line, "env_file'] = ['.env']")) {
            // Check context: is this inside the compose service loop or the single-container section?
            // The single-container one references $this->container_name (line ~2762)
            $context = implode("\n", array_slice($lines, max(0, $lineNum - 5), 10));
            if (! str_contains($context, 'container_name')) {
                $composeEnvFileInjections++;
            }
        }
    }

    expect($composeEnvFileInjections)->toBe(0,
        'Shared .env should not be injected into Docker Compose services (only single-container deployments)'
    );
});

// -- Compose output: environment section is per-service --

it('parser sets per-service environment sections (not shared)', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // The parser merges environment per-service: each service gets its own variables
    // Verify the pattern: $payload['environment'] = $environment->merge($coolifyEnvironments)
    expect($parsersFile)->toContain(
        "\$payload['environment'] = \$environment->merge(\$coolifyEnvironments)->merge(\$serviceNameEnvironments)"
    );
});

it('parser environment merge is per-service, not global', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Environment is merged into $payload per-service, not a shared variable
    expect(str_contains($parsersFile, "payload['environment'] = \$environment->merge"))->toBeTrue(
        'Environment merge should assign to per-service payload'
    );

    // Each service's payload is stored independently
    expect(str_contains($parsersFile, 'parsedServices->put($serviceName, $payload)'))->toBeTrue(
        'Each service payload should be stored independently'
    );
});

// -- Regression: dockerimage still uses env_file --

it('single-container dockerimage still uses env_file', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    // For non-compose single-container deployments (dockerimage, dockerfile, etc.),
    // env_file: ['.env'] is correct because there's only one container.
    expect($jobFile)->toContain(
        "\$docker_compose['services'][\$this->container_name]['env_file'] = ['.env']"
    );
});

// -- End-to-end: compose YAML output should not have .env in env_file --

it('generated compose YAML does not contain env_file .env for multi-service apps', function () {
    // Simulate what the parser outputs for a multi-service compose
    $inputCompose = [
        'services' => [
            'app' => [
                'image' => 'node:18',
                'environment' => ['OPENAI_KEY' => 'sk-xxx'],
            ],
            'db' => [
                'image' => 'postgres:16',
                'environment' => ['POSTGRES_PASSWORD' => 'secret'],
            ],
            'redis' => [
                'image' => 'redis:7',
            ],
        ],
    ];

    // Apply the same env_file logic as the parser (the new version)
    foreach ($inputCompose['services'] as $name => &$service) {
        $existingEnvFiles = data_get($service, 'env_file');
        if (! is_null($existingEnvFiles)) {
            $envFiles = collect(is_array($existingEnvFiles) ? $existingEnvFiles : [$existingEnvFiles])
                ->reject(fn ($f) => $f === '.env')
                ->unique()
                ->values();
            if ($envFiles->isNotEmpty()) {
                $service['env_file'] = $envFiles->toArray();
            } else {
                unset($service['env_file']);
            }
        }
        // If env_file was not defined, don't add it
    }

    $yaml = Yaml::dump($inputCompose, 10);

    // The output YAML should NOT contain env_file: ['.env'] for any service
    expect($yaml)->not->toContain("env_file:\n            - .env");
    expect($yaml)->not->toContain("env_file:\n        - .env");

    // Each service should still have its own environment section
    expect($yaml)->toContain('OPENAI_KEY');
    expect($yaml)->toContain('POSTGRES_PASSWORD');

    // Verify isolation: OPENAI_KEY should NOT appear under db service
    $parsed = Yaml::parse($yaml);
    $dbEnv = data_get($parsed, 'services.db.environment', []);
    $appEnv = data_get($parsed, 'services.app.environment', []);

    expect($appEnv)->toHaveKey('OPENAI_KEY');
    expect($appEnv)->not->toHaveKey('POSTGRES_PASSWORD');
    expect($dbEnv)->toHaveKey('POSTGRES_PASSWORD');
    expect($dbEnv)->not->toHaveKey('OPENAI_KEY');
});
