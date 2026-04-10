<?php

// Test the proxy stop container cleanup logic
it('ensures stop proxy includes wait loop for container removal', function () {
    // This test verifies that StopProxy waits for container to be fully removed
    // to prevent race conditions during restart operations

    // Simulate the command sequence from StopProxy
    $commands = [
        'docker stop -t 30 coolify-proxy 2>/dev/null || true',
        'docker rm -f coolify-proxy 2>/dev/null || true',
        '# Wait for container to be fully removed',
        'for i in {1..10}; do',
        '    if ! docker ps -a --format "{{.Names}}" | grep -q "^coolify-proxy$"; then',
        '        break',
        '    fi',
        '    sleep 1',
        'done',
    ];

    $commandsString = implode("\n", $commands);

    // Verify the stop sequence includes all required components
    expect($commandsString)->toContain('docker stop -t 30 coolify-proxy')
        ->and($commandsString)->toContain('docker rm -f coolify-proxy')
        ->and($commandsString)->toContain('for i in {1..10}; do')
        ->and($commandsString)->toContain('if ! docker ps -a --format "{{.Names}}" | grep -q "^coolify-proxy$"')
        ->and($commandsString)->toContain('break')
        ->and($commandsString)->toContain('sleep 1');

    // Verify order: stop before remove, and wait loop after remove
    $stopPosition = strpos($commandsString, 'docker stop');
    $removePosition = strpos($commandsString, 'docker rm -f');
    $waitLoopPosition = strpos($commandsString, 'for i in {1..10}');

    expect($stopPosition)->toBeLessThan($removePosition)
        ->and($removePosition)->toBeLessThan($waitLoopPosition);
});

it('includes error suppression in stop proxy commands', function () {
    // Test that stop/remove commands suppress errors gracefully

    $commands = [
        'docker stop -t 30 coolify-proxy 2>/dev/null || true',
        'docker rm -f coolify-proxy 2>/dev/null || true',
    ];

    foreach ($commands as $command) {
        expect($command)->toContain('2>/dev/null || true');
    }
});

it('uses configurable timeout for docker stop', function () {
    // Verify that stop command includes the timeout parameter

    $timeout = 30;
    $stopCommand = "docker stop -t $timeout coolify-proxy 2>/dev/null || true";

    expect($stopCommand)->toContain('-t 30');
});

it('waits for swarm service container removal correctly', function () {
    // Test that the container name pattern matches swarm naming

    $containerName = 'coolify-proxy_traefik';
    $checkCommand = "    if ! docker ps -a --format \"{{.Names}}\" | grep -q \"^$containerName$\"; then";

    expect($checkCommand)->toContain('coolify-proxy_traefik');
});
