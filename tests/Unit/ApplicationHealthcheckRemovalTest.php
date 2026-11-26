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

it('reads compound and fractional HEALTHCHECK durations like Docker', function () {
    $application = healthcheckApplication();

    $application->parseHealthcheckFromDockerfile("FROM scratch\nHEALTHCHECK --interval=1m30s --timeout=1.5m --start-period=2m15s --retries=4 CMD [\"/probe\"]");

    expect($application->health_check_interval)->toBe(90)
        ->and($application->health_check_timeout)->toBe(90)
        ->and($application->health_check_start_period)->toBe(135)
        ->and($application->health_check_retries)->toBe(4);
});

it('keeps the current value when a HEALTHCHECK duration is not valid', function () {
    $application = healthcheckApplication();

    $application->parseHealthcheckFromDockerfile("FROM scratch\nHEALTHCHECK --interval=1x --timeout=3000000h --retries=2 CMD [\"/probe\"]");

    expect($application->health_check_interval)->toBe(5)
        ->and($application->health_check_timeout)->toBe(5)
        ->and($application->health_check_retries)->toBe(2);
});

it('converts a Docker duration to whole seconds', function (string $duration, ?int $expected) {
    expect(parseDockerfileInterval($duration))->toBe($expected);
})->with([
    'seconds' => ['30s', 30],
    'no unit' => ['30', 30],
    'minutes' => ['2m', 120],
    'compound' => ['1h2m3s', 3723],
    'fractional' => ['1.5m', 90],
    'fraction without leading digit' => ['.5m', 30],
    'fractional compound' => ['0.3m1.2s', 19],
    'milliseconds' => ['1500ms', 1],
    'microseconds' => ['2000000µs', 2],
    'nanoseconds' => ['3000000000ns', 3],
    'leading plus' => ['+30s', 30],
    'zero' => ['0s', 0],
    'below one second' => ['500ms', 1],
    'negative' => ['-30s', null],
    'unknown unit' => ['5x', null],
    'trailing text' => ['5sec', null],
    'empty' => ['', null],
    'out of range' => ['3000000h', null],
]);

it('reads only the HEALTHCHECK flags before CMD, like Docker', function (string $instruction, array $expected) {
    expect(dockerfileHealthcheckOptions($instruction))->toBe($expected);
})->with([
    'all options' => ['HEALTHCHECK --interval=10s --timeout=3s --start-period=1m --retries=7 CMD ["/probe"]', ['interval' => 10, 'timeout' => 3, 'start_period' => 60, 'retries' => 7]],
    'no options' => ['HEALTHCHECK CMD ["/probe"]', ['interval' => null, 'timeout' => null, 'start_period' => null, 'retries' => null]],
    'quoted values' => ['HEALTHCHECK --interval="18s" --timeout=\'4s\' CMD /probe', ['interval' => 18, 'timeout' => 4, 'start_period' => null, 'retries' => null]],
    'flags of the command are not options' => ['HEALTHCHECK --interval=19s CMD /probe --timeout=99s --retries=9', ['interval' => 19, 'timeout' => null, 'start_period' => null, 'retries' => null]],
    'zero uses the Docker defaults' => ['HEALTHCHECK --interval=0s --timeout=0 --start-period=0s --retries=0 CMD /probe', ['interval' => 30, 'timeout' => 30, 'start_period' => 0, 'retries' => 3]],
    'start-interval is not interval' => ['HEALTHCHECK --start-interval=46s CMD /probe', ['interval' => null, 'timeout' => null, 'start_period' => null, 'retries' => null]],
    'retries with plus sign' => ['HEALTHCHECK --retries=+4 CMD /probe', ['interval' => null, 'timeout' => null, 'start_period' => null, 'retries' => 4]],
    'retries not an integer' => ['HEALTHCHECK --retries=1.5 CMD /probe', ['interval' => null, 'timeout' => null, 'start_period' => null, 'retries' => null]],
]);

it('reads Dockerfile syntax around HEALTHCHECK like Docker', function (string $dockerfile, ?string $expected) {
    expect(dockerfileHealthcheckInstruction($dockerfile))->toBe($expected);
})->with([
    'escape directive' => ["# escape=`\nFROM scratch\nHEALTHCHECK --interval=24s `\n  --timeout=6s CMD /probe", 'HEALTHCHECK --interval=24s --timeout=6s CMD /probe'],
    'escape directive after syntax directive' => ["# syntax=docker/dockerfile:1\n# escape=`\nFROM scratch\nHEALTHCHECK --interval=49s `\n  CMD /probe", 'HEALTHCHECK --interval=49s CMD /probe'],
    'escape comment after an instruction' => ["FROM scratch\n# escape=`\nHEALTHCHECK --interval=50s \\\n  CMD /probe", 'HEALTHCHECK --interval=50s CMD /probe'],
    'backslash in command with backtick escape' => ["# escape=`\nFROM scratch\nHEALTHCHECK CMD c:\\probe.exe\\", 'HEALTHCHECK CMD c:\\probe.exe\\'],
    'continuation with trailing space' => ["FROM scratch\nHEALTHCHECK --interval=48s \\  \n  CMD /probe", 'HEALTHCHECK --interval=48s CMD /probe'],
    'CRLF line endings' => ["FROM scratch\r\nHEALTHCHECK --interval=27s \\\r\n  CMD /probe\r\n", 'HEALTHCHECK --interval=27s CMD /probe'],
    'NONE with flags' => ["FROM scratch\nHEALTHCHECK --interval=44s NONE", null],
]);
