<?php

use App\Models\Server;

it('normalizes requested log line counts', function () {
    if (! function_exists('normalizeLogLines')) {
        expect(function_exists('normalizeLogLines'))->toBeTrue();

        return;
    }

    expect(normalizeLogLines(null))->toBe(100)
        ->and(normalizeLogLines(''))->toBe(100)
        ->and(normalizeLogLines('abc'))->toBe(100)
        ->and(normalizeLogLines('0'))->toBe(100)
        ->and(normalizeLogLines('-5'))->toBe(100)
        ->and(normalizeLogLines('50'))->toBe(50)
        ->and(normalizeLogLines('50000'))->toBe(10000);
});

it('parses show_timestamps query values as booleans', function () {
    if (! function_exists('parseLogTimestampFlag')) {
        expect(function_exists('parseLogTimestampFlag'))->toBeTrue();

        return;
    }

    expect(parseLogTimestampFlag('true'))->toBeTrue()
        ->and(parseLogTimestampFlag('1'))->toBeTrue()
        ->and(parseLogTimestampFlag(true))->toBeTrue()
        ->and(parseLogTimestampFlag('false'))->toBeFalse()
        ->and(parseLogTimestampFlag('0'))->toBeFalse()
        ->and(parseLogTimestampFlag(false))->toBeFalse()
        ->and(parseLogTimestampFlag('not-a-bool'))->toBeFalse()
        ->and(parseLogTimestampFlag(null))->toBeFalse();
});

it('builds docker log commands with options before an escaped container id', function () {
    if (! function_exists('buildContainerLogsCommand')) {
        expect(function_exists('buildContainerLogsCommand'))->toBeTrue();

        return;
    }

    $server = new Server;
    $server->settings = ['is_swarm_manager' => false];

    expect(buildContainerLogsCommand($server, 'container-1', 25, true))
        ->toBe("docker logs -n 25 --timestamps 'container-1' 2>&1");
});

it('builds swarm service log commands with options before an escaped service id', function () {
    if (! function_exists('buildContainerLogsCommand')) {
        expect(function_exists('buildContainerLogsCommand'))->toBeTrue();

        return;
    }

    $server = new Server;
    $server->settings = ['is_swarm_manager' => true];

    expect(buildContainerLogsCommand($server, "service'name", 25, true))
        ->toBe("docker service logs -n 25 --timestamps 'service'\\''name' 2>&1");
});

it('filters service sub containers in PHP instead of using user input in shell filters', function () {
    if (! function_exists('filterServiceSubContainersByName')) {
        expect(function_exists('filterServiceSubContainersByName'))->toBeTrue();

        return;
    }

    $containers = collect([
        ['ID' => 'first', 'Labels' => 'coolify.serviceId=10,coolify.name=app-service-uuid,coolify.type=service'],
        ['ID' => 'second', 'Labels' => 'coolify.serviceId=10,coolify.name=db-service-uuid,coolify.type=service'],
        ['ID' => 'third', 'Labels' => ['coolify.name' => 'app-service-uuid']],
    ]);

    expect(filterServiceSubContainersByName($containers, 'app-service-uuid')->pluck('ID')->all())
        ->toBe(['first', 'third']);
});

it('does not interpolate the requested service name into the docker ps shell command', function () {
    $source = file_get_contents(__DIR__.'/../../../bootstrap/helpers/docker.php');

    expect($source)->not->toContain('coolify.name={$name}');
});
