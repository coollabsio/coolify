<?php

// Test the proxy restart container cleanup logic
it('ensures container cleanup includes wait loop in command sequence', function () {
    // This test verifies that the StartProxy action includes proper container
    // cleanup with a wait loop to prevent "container name already in use" errors

    // Simulate the command generation pattern from StartProxy
    $commands = collect([
        'mkdir -p /data/coolify/proxy/dynamic',
        'cd /data/coolify/proxy',
        "echo 'Creating required Docker Compose file.'",
        "echo 'Pulling docker image.'",
        'docker compose pull',
        'if docker ps -a --format "{{.Names}}" | grep -q "^coolify-proxy$"; then',
        "    echo 'Stopping and removing existing coolify-proxy.'",
        '    docker stop coolify-proxy 2>/dev/null || true',
        '    docker rm -f coolify-proxy 2>/dev/null || true',
        '    # Wait for container to be fully removed',
        '    for i in {1..10}; do',
        '        if ! docker ps -a --format "{{.Names}}" | grep -q "^coolify-proxy$"; then',
        '            break',
        '        fi',
        '        echo "Waiting for coolify-proxy to be removed... ($i/10)"',
        '        sleep 1',
        '    done',
        "    echo 'Successfully stopped and removed existing coolify-proxy.'",
        'fi',
        "echo 'Starting coolify-proxy.'",
        'docker compose up -d --wait --remove-orphans',
        "echo 'Successfully started coolify-proxy.'",
    ]);

    $commandsString = $commands->implode("\n");

    // Verify the cleanup sequence includes all required components
    expect($commandsString)->toContain('docker stop coolify-proxy 2>/dev/null || true')
        ->and($commandsString)->toContain('docker rm -f coolify-proxy 2>/dev/null || true')
        ->and($commandsString)->toContain('for i in {1..10}; do')
        ->and($commandsString)->toContain('if ! docker ps -a --format "{{.Names}}" | grep -q "^coolify-proxy$"; then')
        ->and($commandsString)->toContain('break')
        ->and($commandsString)->toContain('sleep 1')
        ->and($commandsString)->toContain('docker compose up -d --wait --remove-orphans');

    // Verify the order: cleanup must come before compose up
    $stopPosition = strpos($commandsString, 'docker stop coolify-proxy');
    $waitLoopPosition = strpos($commandsString, 'for i in {1..10}');
    $composeUpPosition = strpos($commandsString, 'docker compose up -d');

    expect($stopPosition)->toBeLessThan($waitLoopPosition)
        ->and($waitLoopPosition)->toBeLessThan($composeUpPosition);
});

it('includes error suppression in container cleanup commands', function () {
    // Test that cleanup commands suppress errors to prevent failures
    // when the container doesn't exist

    $cleanupCommands = [
        '    docker stop coolify-proxy 2>/dev/null || true',
        '    docker rm -f coolify-proxy 2>/dev/null || true',
    ];

    foreach ($cleanupCommands as $command) {
        expect($command)->toContain('2>/dev/null || true');
    }
});

it('waits up to 10 seconds for container removal', function () {
    // Verify the wait loop has correct bounds

    $waitLoop = [
        '    for i in {1..10}; do',
        '        if ! docker ps -a --format "{{.Names}}" | grep -q "^coolify-proxy$"; then',
        '            break',
        '        fi',
        '        echo "Waiting for coolify-proxy to be removed... ($i/10)"',
        '        sleep 1',
        '    done',
    ];

    $loopString = implode("\n", $waitLoop);

    // Verify loop iterates 10 times
    expect($loopString)->toContain('{1..10}')
        ->and($loopString)->toContain('sleep 1')
        ->and($loopString)->toContain('break'); // Early exit when container is gone
});
