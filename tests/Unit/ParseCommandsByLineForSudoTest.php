<?php

use App\Models\Server;

beforeEach(function () {
    // Create a mock server with non-root user
    $this->server = Mockery::mock(Server::class)->makePartial();
    $this->server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $this->server->shouldReceive('setAttribute')->andReturnSelf();
    $this->server->user = 'ubuntu';
});

afterEach(function () {
    Mockery::close();
});

test('wraps complex Docker install command with pipes in bash -c', function () {
    $commands = collect([
        'curl https://releases.rancher.com/install-docker/27.3.sh | sh || curl https://get.docker.com | sh',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe("sudo bash -c 'curl https://releases.rancher.com/install-docker/27.3.sh | sh || curl https://get.docker.com | sh'");
});

test('wraps complex Docker install command with multiple fallbacks', function () {
    $commands = collect([
        'curl --max-time 300 https://releases.rancher.com/install-docker/27.3.sh | sh || curl https://get.docker.com | sh -s -- --version 27.3',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe("sudo bash -c 'curl --max-time 300 https://releases.rancher.com/install-docker/27.3.sh | sh || curl https://get.docker.com | sh -s -- --version 27.3'");
});

test('wraps command with pipe to bash in bash -c', function () {
    $commands = collect([
        'curl https://example.com/script.sh | bash',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe("sudo bash -c 'curl https://example.com/script.sh | bash'");
});

test('wraps complex command with pipes and && operators', function () {
    $commands = collect([
        'curl https://example.com | sh && echo "done"',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe("sudo bash -c 'curl https://example.com | sh && echo \"done\"'");
});

test('escapes single quotes in complex piped commands', function () {
    $commands = collect([
        "curl https://example.com | sh -c 'echo \"test\"'",
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe("sudo bash -c 'curl https://example.com | sh -c '\\''echo \"test\"'\\'''");
});

test('handles simple command without pipes or operators', function () {
    $commands = collect([
        'apt-get update',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo apt-get update');
});

test('handles command with double ampersand operator but no pipes', function () {
    $commands = collect([
        'mkdir -p /foo && chown ubuntu /foo',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo mkdir -p /foo && sudo chown ubuntu /foo');
});

test('handles command with double pipe operator but no pipes', function () {
    $commands = collect([
        'command -v docker || echo "not found"',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    // 'command' is exempted from sudo, but echo gets sudo after ||
    expect($result[0])->toBe('command -v docker || sudo echo "not found"');
});

test('handles command with simple pipe but no operators', function () {
    $commands = collect([
        'cat file | grep pattern',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo cat file | sudo grep pattern');
});

test('handles command with subshell $(...)', function () {
    $commands = collect([
        'echo $(whoami)',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    // 'echo' is exempted from sudo at the start
    expect($result[0])->toBe('echo $(sudo whoami)');
});

test('skips sudo for cd commands', function () {
    $commands = collect([
        'cd /var/www',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('cd /var/www');
});

test('skips sudo for echo commands', function () {
    $commands = collect([
        'echo "test"',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('echo "test"');
});

test('skips sudo for command commands', function () {
    $commands = collect([
        'command -v docker',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('command -v docker');
});

test('skips sudo for true commands', function () {
    $commands = collect([
        'true',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('true');
});

test('handles if statements by adding sudo to condition', function () {
    $commands = collect([
        'if command -v docker',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('if sudo command -v docker');
});

test('skips sudo for fi statements', function () {
    $commands = collect([
        'fi',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('fi');
});

test('adds ownership changes for Coolify data paths', function () {
    $commands = collect([
        'mkdir -p /data/coolify/logs',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    // Note: The && operator adds another sudo, creating double sudo for chown/chmod
    // This is existing behavior that may need refactoring but isn't part of this bug fix
    expect($result[0])->toBe('sudo mkdir -p /data/coolify/logs && sudo sudo chown -R ubuntu:ubuntu /data/coolify/logs && sudo sudo chmod -R o-rwx /data/coolify/logs');
});

test('adds ownership changes for Coolify tmp paths', function () {
    $commands = collect([
        'mkdir -p /tmp/coolify/cache',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    // Note: The && operator adds another sudo, creating double sudo for chown/chmod
    // This is existing behavior that may need refactoring but isn't part of this bug fix
    expect($result[0])->toBe('sudo mkdir -p /tmp/coolify/cache && sudo sudo chown -R ubuntu:ubuntu /tmp/coolify/cache && sudo sudo chmod -R o-rwx /tmp/coolify/cache');
});

test('does not add ownership changes for system paths', function () {
    $commands = collect([
        'mkdir -p /var/log',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo mkdir -p /var/log');
});

test('handles multiple commands in sequence', function () {
    $commands = collect([
        'apt-get update',
        'apt-get install -y docker',
        'curl https://get.docker.com | sh',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result)->toHaveCount(3);
    expect($result[0])->toBe('sudo apt-get update');
    expect($result[1])->toBe('sudo apt-get install -y docker');
    expect($result[2])->toBe("sudo bash -c 'curl https://get.docker.com | sh'");
});

test('handles empty command list', function () {
    $commands = collect([]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result)->toBeArray();
    expect($result)->toHaveCount(0);
});

test('handles real-world Docker installation command from InstallDocker action', function () {
    $version = '27.3';
    $commands = collect([
        "curl --max-time 300 --retry 3 https://releases.rancher.com/install-docker/{$version}.sh | sh || curl --max-time 300 --retry 3 https://get.docker.com | sh -s -- --version {$version}",
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toStartWith("sudo bash -c '");
    expect($result[0])->toEndWith("'");
    expect($result[0])->toContain('curl --max-time 300');
    expect($result[0])->toContain('| sh');
    expect($result[0])->toContain('||');
    expect($result[0])->not->toContain('| sudo sh');
});

test('preserves command structure in wrapped bash -c', function () {
    $commands = collect([
        'curl https://example.com | sh || curl https://backup.com | sh',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    // The command should be wrapped without breaking the pipe and fallback structure
    expect($result[0])->toBe("sudo bash -c 'curl https://example.com | sh || curl https://backup.com | sh'");

    // Verify it doesn't contain broken patterns like "| sudo sh"
    expect($result[0])->not->toContain('| sudo sh');
    expect($result[0])->not->toContain('|| sudo curl');
});

test('handles command with mixed operators and subshells', function () {
    $commands = collect([
        'docker ps || echo $(date)',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    // This should use the original logic since it's not a complex pipe command
    expect($result[0])->toBe('sudo docker ps || sudo echo $(sudo date)');
});

test('handles whitespace-only commands gracefully', function () {
    $commands = collect([
        '   ',
        '',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result)->toHaveCount(2);
});

test('detects pipe to sh with additional arguments', function () {
    $commands = collect([
        'curl https://example.com | sh -s -- --arg1 --arg2',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe("sudo bash -c 'curl https://example.com | sh -s -- --arg1 --arg2'");
});

test('handles command chains with both && and || operators with pipes', function () {
    $commands = collect([
        'curl https://first.com | sh && echo "success" || curl https://backup.com | sh',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toStartWith("sudo bash -c '");
    expect($result[0])->toEndWith("'");
    expect($result[0])->not->toContain('| sudo');
});
