<?php

it('passes a simple command through correctly', function () {
    $result = executeInDocker('test-container', 'ls -la /app');

    expect($result)->toBe("docker exec test-container bash -c 'ls -la /app'");
});

it('escapes single quotes in command', function () {
    $result = executeInDocker('test-container', "echo 'hello world'");

    expect($result)->toBe("docker exec test-container bash -c 'echo '\\''hello world'\\'''");
});

it('prevents command injection via single quote breakout', function () {
    $malicious = "cd /dir && docker compose build'; id; #";
    $result = executeInDocker('test-container', $malicious);

    // The single quote in the malicious command should be escaped so it cannot break out of bash -c
    // The raw unescaped pattern "build'; id;" must not appear — the quote must be escaped
    expect($result)->not->toContain("build'; id;");
    expect($result)->toBe("docker exec test-container bash -c 'cd /dir && docker compose build'\\''; id; #'");
});

it('handles empty command', function () {
    $result = executeInDocker('test-container', '');

    expect($result)->toBe("docker exec test-container bash -c ''");
});

it('handles command with multiple single quotes', function () {
    $result = executeInDocker('test-container', "echo 'a' && echo 'b'");

    expect($result)->toBe("docker exec test-container bash -c 'echo '\\''a'\\'' && echo '\\''b'\\'''");
});
