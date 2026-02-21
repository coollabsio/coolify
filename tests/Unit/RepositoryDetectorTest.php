<?php

use App\Enums\BuildPackTypes;
use App\Services\RepositoryDetector;

test('parseOutput handles complete detection output', function () {
    $output = json_encode([
        'dockerfiles' => ['Dockerfile', 'apps/api/Dockerfile'],
        'dockerComposeFiles' => ['docker-compose.yml'],
        'envFiles' => ['.env.example' => "APP_NAME=MyApp\nAPP_ENV=production\nDB_HOST=localhost\nDB_PORT=5432"],
        'dockerfilePorts' => ['Dockerfile' => 3000, 'apps/api/Dockerfile' => 8080],
    ]);

    $detector = new RepositoryDetector(
        repositoryUrl: 'https://github.com/test/repo',
        branch: 'main',
        baseDirectory: '/',
        serverId: 1,
        teamId: 1,
    );

    $reflection = new ReflectionClass($detector);
    $method = $reflection->getMethod('parseOutput');

    $result = $method->invoke($detector, $output);

    expect($result->dockerfiles)->toBe(['Dockerfile', 'apps/api/Dockerfile'])
        ->and($result->dockerComposeFiles)->toBe(['docker-compose.yml'])
        ->and($result->envFiles)->toHaveKey('.env.example')
        ->and($result->envFiles['.env.example'])->toContain('APP_NAME=MyApp')
        ->and($result->dockerfilePorts)->toBe(['Dockerfile' => 3000, 'apps/api/Dockerfile' => 8080])
        ->and($result->getSuggestedBuildPack())->toBe(BuildPackTypes::DOCKERCOMPOSE);
});

test('parseOutput handles empty repository', function () {
    $output = json_encode([
        'dockerfiles' => [],
        'dockerComposeFiles' => [],
        'envFiles' => (object) [],
        'dockerfilePorts' => (object) [],
    ]);

    $detector = new RepositoryDetector(
        repositoryUrl: 'https://github.com/test/repo',
        branch: 'main',
        baseDirectory: '/',
        serverId: 1,
        teamId: 1,
    );

    $reflection = new ReflectionClass($detector);
    $method = $reflection->getMethod('parseOutput');

    $result = $method->invoke($detector, $output);

    expect($result->dockerfiles)->toBe([])
        ->and($result->dockerComposeFiles)->toBe([])
        ->and($result->envFiles)->toBe([])
        ->and($result->dockerfilePorts)->toBe([])
        ->and($result->getSuggestedBuildPack())->toBe(BuildPackTypes::NIXPACKS);
});

test('parseOutput handles dockerfile without EXPOSE', function () {
    $output = json_encode([
        'dockerfiles' => ['Dockerfile'],
        'dockerComposeFiles' => [],
        'envFiles' => (object) [],
        'dockerfilePorts' => ['Dockerfile' => null],
    ]);

    $detector = new RepositoryDetector(
        repositoryUrl: 'https://github.com/test/repo',
        branch: 'main',
        baseDirectory: '/',
        serverId: 1,
        teamId: 1,
    );

    $reflection = new ReflectionClass($detector);
    $method = $reflection->getMethod('parseOutput');

    $result = $method->invoke($detector, $output);

    expect($result->dockerfiles)->toBe(['Dockerfile'])
        ->and($result->dockerfilePorts)->toBe(['Dockerfile' => null])
        ->and($result->getSuggestedBuildPack())->toBe(BuildPackTypes::DOCKERFILE);
});

test('parseOutput handles only env files without dockerfiles', function () {
    $output = json_encode([
        'dockerfiles' => [],
        'dockerComposeFiles' => [],
        'envFiles' => ['.env.example' => "SECRET_KEY=changeme\nDATABASE_URL=postgres://localhost/db"],
        'dockerfilePorts' => (object) [],
    ]);

    $detector = new RepositoryDetector(
        repositoryUrl: 'https://github.com/test/repo',
        branch: 'main',
        baseDirectory: '/',
        serverId: 1,
        teamId: 1,
    );

    $reflection = new ReflectionClass($detector);
    $method = $reflection->getMethod('parseOutput');

    $result = $method->invoke($detector, $output);

    expect($result->dockerfiles)->toBe([])
        ->and($result->envFiles)->toHaveKey('.env.example')
        ->and($result->envFiles['.env.example'])->toContain('SECRET_KEY=changeme')
        ->and($result->envFiles['.env.example'])->toContain('DATABASE_URL=postgres://localhost/db')
        ->and($result->getSuggestedBuildPack())->toBe(BuildPackTypes::NIXPACKS);
});

test('parseOutput handles multiple compose files', function () {
    $output = json_encode([
        'dockerfiles' => [],
        'dockerComposeFiles' => ['docker-compose.yml', 'compose.yaml'],
        'envFiles' => (object) [],
        'dockerfilePorts' => (object) [],
    ]);

    $detector = new RepositoryDetector(
        repositoryUrl: 'https://github.com/test/repo',
        branch: 'main',
        baseDirectory: '/',
        serverId: 1,
        teamId: 1,
    );

    $reflection = new ReflectionClass($detector);
    $method = $reflection->getMethod('parseOutput');

    $result = $method->invoke($detector, $output);

    expect($result->dockerComposeFiles)->toBe(['docker-compose.yml', 'compose.yaml'])
        ->and($result->getSuggestedBuildPack())->toBe(BuildPackTypes::DOCKERCOMPOSE);
});

test('parseOutput handles multiple env files', function () {
    $output = json_encode([
        'dockerfiles' => [],
        'dockerComposeFiles' => [],
        'envFiles' => [
            '.env.example' => "APP_KEY=base64:abc\nAPP_ENV=local",
            '.env.sample' => "DB_HOST=127.0.0.1\nDB_PORT=5432",
            '.env.dist' => 'REDIS_HOST=localhost',
        ],
        'dockerfilePorts' => (object) [],
    ]);

    $detector = new RepositoryDetector(
        repositoryUrl: 'https://github.com/test/repo',
        branch: 'main',
        baseDirectory: '/',
        serverId: 1,
        teamId: 1,
    );

    $reflection = new ReflectionClass($detector);
    $method = $reflection->getMethod('parseOutput');

    $result = $method->invoke($detector, $output);

    expect($result->envFiles)->toHaveCount(3)
        ->and($result->envFiles['.env.example'])->toContain('APP_KEY=base64:abc')
        ->and($result->envFiles['.env.sample'])->toContain('DB_HOST=127.0.0.1')
        ->and($result->envFiles['.env.dist'])->toContain('REDIS_HOST=localhost')
        ->and($result->hasEnvFiles())->toBeTrue();
});

test('parseOutput returns none for invalid JSON', function () {
    $detector = new RepositoryDetector(
        repositoryUrl: 'https://github.com/test/repo',
        branch: 'main',
        baseDirectory: '/',
        serverId: 1,
        teamId: 1,
    );

    $reflection = new ReflectionClass($detector);
    $method = $reflection->getMethod('parseOutput');

    $result = $method->invoke($detector, 'not valid json');

    expect($result->dockerfiles)->toBe([])
        ->and($result->dockerComposeFiles)->toBe([])
        ->and($result->envFiles)->toBe([])
        ->and($result->dockerfilePorts)->toBe([]);
});
