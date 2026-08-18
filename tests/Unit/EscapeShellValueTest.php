<?php

it('wraps a simple value in single quotes', function () {
    expect(escapeShellValue('hello'))->toBe("'hello'");
});

it('escapes single quotes in the value', function () {
    expect(escapeShellValue("it's"))->toBe("'it'\\''s'");
});

it('handles empty string', function () {
    expect(escapeShellValue(''))->toBe("''");
});

it('preserves && in a single-quoted value', function () {
    $result = escapeShellValue('npx prisma generate && npm run build');
    expect($result)->toBe("'npx prisma generate && npm run build'");
});

it('preserves special shell characters in value', function () {
    $result = escapeShellValue('echo $HOME; rm -rf /');
    expect($result)->toBe("'echo \$HOME; rm -rf /'");
});

it('handles value with double quotes', function () {
    $result = escapeShellValue('say "hello"');
    expect($result)->toBe("'say \"hello\"'");
});

it('produces correct output when passed through executeInDocker', function () {
    // Simulate the exact issue from GitHub #9042:
    // NIXPACKS_BUILD_CMD with chained && commands
    $envValue = 'npx prisma generate && npx prisma db push && npm run build';
    $escapedEnv = '--env '.escapeShellValue("NIXPACKS_BUILD_CMD={$envValue}");

    $command = "nixpacks plan -f json {$escapedEnv} /app";
    $dockerCmd = executeInDocker('test-container', $command);

    // The && must NOT appear unquoted at the bash -c level
    // The full docker command should properly nest the quoting
    expect($dockerCmd)->toContain('NIXPACKS_BUILD_CMD=npx prisma generate && npx prisma db push && npm run build');
    // Verify it's wrapped in docker exec bash -c
    expect($dockerCmd)->toStartWith("docker exec test-container bash -c '");
    expect($dockerCmd)->toEndWith("'");
});

it('produces correct output for build-cmd with chained commands through executeInDocker', function () {
    $buildCmd = 'npx prisma generate && npm run build';
    $escapedCmd = escapeShellValue($buildCmd);

    $command = "nixpacks plan -f json --build-cmd {$escapedCmd} /app";
    $dockerCmd = executeInDocker('test-container', $command);

    // The build command value must remain intact inside the quoting
    expect($dockerCmd)->toContain('npx prisma generate && npm run build');
    expect($dockerCmd)->toStartWith("docker exec test-container bash -c '");
});
