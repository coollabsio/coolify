<?php

use App\Models\Application;

function healthcheckApplication(array $attributes = []): Application
{
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('save')->andReturnTrue();
    $application->forceFill([
        'health_check_enabled' => false,
        'custom_healthcheck_found' => false,
        'health_check_interval' => 5,
        'health_check_timeout' => 5,
        'health_check_retries' => 10,
        'health_check_start_period' => 5,
        'dockerfile_target_build' => null,
        ...$attributes,
    ]);

    return $application;
}

const MULTI_STAGE_DOCKERFILE = <<<'DOCKERFILE'
FROM node:20-alpine AS builder
RUN npm ci

FROM node:20-alpine AS api
COPY --from=builder /app/dist ./dist
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD node -e "require('http').get('http://localhost:3000/health')"
CMD ["node", "dist/main.js"]

# === Stage: worker ===
FROM node:20-alpine AS worker
COPY --from=builder /app/dist ./dist
# No HEALTHCHECK - this stage has no HTTP server
CMD ["node", "dist/worker.js"]
DOCKERFILE;

it('resets the custom healthcheck when HEALTHCHECK is removed from the Dockerfile', function () {
    $application = healthcheckApplication(['custom_healthcheck_found' => true, 'health_check_interval' => 30]);

    $application->parseHealthcheckFromDockerfile("FROM nginx:latest\nCOPY . /app\nEXPOSE 80");

    expect($application->custom_healthcheck_found)->toBeFalse()
        ->and($application->health_check_interval)->toBe(5);
});

it('reads the HEALTHCHECK options of a single-stage Dockerfile', function () {
    $application = healthcheckApplication();

    $application->parseHealthcheckFromDockerfile("FROM nginx:latest\nHEALTHCHECK --interval=30s --retries=4 \\\n  CMD curl -f http://localhost/\nEXPOSE 80");

    expect($application->custom_healthcheck_found)->toBeTrue()
        ->and($application->health_check_interval)->toBe(30)
        ->and($application->health_check_retries)->toBe(4);
});

it('ignores a HEALTHCHECK of another stage than the build target', function () {
    $application = healthcheckApplication(['dockerfile_target_build' => 'worker']);

    $application->parseHealthcheckFromDockerfile(MULTI_STAGE_DOCKERFILE);

    expect($application->custom_healthcheck_found)->toBeFalse()
        ->and($application->health_check_interval)->toBe(5);
});

it('resets a custom healthcheck found earlier when the build target has no HEALTHCHECK', function () {
    $application = healthcheckApplication(['dockerfile_target_build' => 'worker', 'custom_healthcheck_found' => true]);

    $application->parseHealthcheckFromDockerfile(MULTI_STAGE_DOCKERFILE);

    expect($application->custom_healthcheck_found)->toBeFalse();
});

it('reads the HEALTHCHECK of the build target stage', function () {
    $application = healthcheckApplication(['dockerfile_target_build' => 'API']);

    $application->parseHealthcheckFromDockerfile(MULTI_STAGE_DOCKERFILE);

    expect($application->custom_healthcheck_found)->toBeTrue()
        ->and($application->health_check_interval)->toBe(30)
        ->and($application->health_check_start_period)->toBe(15)
        ->and($application->health_check_retries)->toBe(3);
});

it('finds the HEALTHCHECK that applies to the built image', function (string $dockerfile, ?string $target, ?string $expected) {
    expect(dockerfileHealthcheckInstruction($dockerfile, $target))->toBe($expected);
})->with([
    'last stage when no target is set' => [MULTI_STAGE_DOCKERFILE, null, null],
    'HEALTHCHECK only in a builder stage' => ["FROM node AS build\nHEALTHCHECK CMD true\nFROM nginx\nCOPY --from=build /a /b", null, null],
    'inherited from a parent stage' => ["FROM node AS base\nHEALTHCHECK --interval=10s CMD true\nFROM base AS app\nCMD run", 'app', 'HEALTHCHECK --interval=10s CMD true'],
    'HEALTHCHECK NONE overrides the parent stage' => ["FROM node AS base\nHEALTHCHECK CMD true\nFROM base AS worker\nHEALTHCHECK NONE", 'worker', null],
    'last HEALTHCHECK of a stage wins' => ["FROM node\nHEALTHCHECK --interval=1s CMD a\nHEALTHCHECK --interval=2s CMD b", null, 'HEALTHCHECK --interval=2s CMD b'],
    'FROM with platform flag' => ["FROM --platform=linux/amd64 node AS app\nhealthcheck --retries=2 CMD true", 'app', 'healthcheck --retries=2 CMD true'],
    'target that does not exist' => ["FROM node AS app\nHEALTHCHECK CMD true", 'missing', null],
    'comment mentions HEALTHCHECK' => ["FROM node\n# HEALTHCHECK is not needed\nCMD run", null, null],
    'empty Dockerfile' => ['', null, null],
]);
