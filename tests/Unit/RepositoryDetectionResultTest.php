<?php

use App\Data\RepositoryDetectionResult;
use App\Enums\BuildPackTypes;

test('empty result returns correct defaults', function () {
    $result = RepositoryDetectionResult::none();

    expect($result->dockerfiles)->toBe([])
        ->and($result->dockerComposeFiles)->toBe([])
        ->and($result->envFiles)->toBe([])
        ->and($result->dockerfilePorts)->toBe([]);
});

test('getSuggestedBuildPack returns dockercompose when compose files found', function () {
    $result = new RepositoryDetectionResult(
        dockerfiles: ['Dockerfile'],
        dockerComposeFiles: ['docker-compose.yml'],
    );

    expect($result->getSuggestedBuildPack())->toBe(BuildPackTypes::DOCKERCOMPOSE);
});

test('getSuggestedBuildPack returns dockerfile when only dockerfiles found', function () {
    $result = new RepositoryDetectionResult(
        dockerfiles: ['Dockerfile'],
    );

    expect($result->getSuggestedBuildPack())->toBe(BuildPackTypes::DOCKERFILE);
});

test('getSuggestedBuildPack returns nixpacks when nothing found', function () {
    $result = RepositoryDetectionResult::none();

    expect($result->getSuggestedBuildPack())->toBe(BuildPackTypes::NIXPACKS);
});

test('hasDockerfile returns true when dockerfiles present', function () {
    $result = new RepositoryDetectionResult(
        dockerfiles: ['Dockerfile', 'apps/api/Dockerfile'],
    );

    expect($result->hasDockerfile())->toBeTrue();
});

test('hasDockerfile returns false when no dockerfiles', function () {
    $result = RepositoryDetectionResult::none();

    expect($result->hasDockerfile())->toBeFalse();
});

test('hasDockerCompose returns true when compose files present', function () {
    $result = new RepositoryDetectionResult(
        dockerComposeFiles: ['docker-compose.yml'],
    );

    expect($result->hasDockerCompose())->toBeTrue();
});

test('hasDockerCompose returns false when no compose files', function () {
    $result = RepositoryDetectionResult::none();

    expect($result->hasDockerCompose())->toBeFalse();
});

test('dockerfilePorts stores port mapping correctly', function () {
    $result = new RepositoryDetectionResult(
        dockerfiles: ['Dockerfile', 'apps/api/Dockerfile'],
        dockerfilePorts: ['Dockerfile' => 3000, 'apps/api/Dockerfile' => 8080],
    );

    expect($result->dockerfilePorts)->toBe(['Dockerfile' => 3000, 'apps/api/Dockerfile' => 8080])
        ->and($result->dockerfilePorts['Dockerfile'])->toBe(3000)
        ->and($result->dockerfilePorts['apps/api/Dockerfile'])->toBe(8080);
});

test('hasEnvFiles returns true when env files present', function () {
    $result = new RepositoryDetectionResult(
        envFiles: ['.env.example' => 'APP_KEY=secret'],
    );

    expect($result->hasEnvFiles())->toBeTrue();
});

test('hasEnvFiles returns false when no env files', function () {
    $result = RepositoryDetectionResult::none();

    expect($result->hasEnvFiles())->toBeFalse();
});

test('envFiles stores multiple files with content', function () {
    $result = new RepositoryDetectionResult(
        envFiles: [
            '.env.example' => 'APP_KEY=secret',
            '.env.sample' => 'DB_HOST=localhost',
            '.env.dist' => null,
        ],
    );

    expect($result->envFiles)->toHaveCount(3)
        ->and($result->envFiles['.env.example'])->toBe('APP_KEY=secret')
        ->and($result->envFiles['.env.sample'])->toBe('DB_HOST=localhost')
        ->and($result->envFiles['.env.dist'])->toBeNull();
});
