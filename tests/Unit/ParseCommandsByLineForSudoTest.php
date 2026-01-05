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

    // docker commands now correctly get sudo prefix (word boundary fix for 'do' keyword)
    // The || operator adds sudo to what follows, and subshell adds sudo inside $()
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

test('skips sudo for bash control structure keywords - for loop', function () {
    $commands = collect([
        '    for i in {1..10}; do',
        '        echo $i',
        '    done',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    // Control structure keywords should not have sudo prefix
    expect($result[0])->toBe('    for i in {1..10}; do');
    expect($result[1])->toBe('        echo $i');
    expect($result[2])->toBe('    done');
});

test('skips sudo for bash control structure keywords - while loop', function () {
    $commands = collect([
        'while true; do',
        '    echo "running"',
        'done',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('while true; do');
    expect($result[1])->toBe('    echo "running"');
    expect($result[2])->toBe('done');
});

test('skips sudo for bash control structure keywords - case statement', function () {
    $commands = collect([
        'case $1 in',
        '    start)',
        '        systemctl start service',
        '        ;;',
        'esac',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('case $1 in');
    // Note: '    start)' gets sudo because 'start)' doesn't match any excluded keyword
    // The sudo is added at the start of the line, before indentation
    expect($result[1])->toBe('sudo     start)');
    expect($result[2])->toBe('sudo         systemctl start service');
    expect($result[3])->toBe('sudo         ;;');
    expect($result[4])->toBe('esac');
});

test('skips sudo for bash control structure keywords - if then else', function () {
    $commands = collect([
        'if [ -f /tmp/file ]; then',
        '    cat /tmp/file',
        'else',
        '    touch /tmp/file',
        'fi',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('if sudo [ -f /tmp/file ]; then');
    // Note: sudo is added at the start of line (before indentation) for non-excluded commands
    expect($result[1])->toBe('sudo     cat /tmp/file');
    expect($result[2])->toBe('else');
    expect($result[3])->toBe('sudo     touch /tmp/file');
    expect($result[4])->toBe('fi');
});

test('skips sudo for bash control structure keywords - elif', function () {
    $commands = collect([
        'if [ $x -eq 1 ]; then',
        '    echo "one"',
        'elif [ $x -eq 2 ]; then',
        '    echo "two"',
        'else',
        '    echo "other"',
        'fi',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('if sudo [ $x -eq 1 ]; then');
    expect($result[1])->toBe('    echo "one"');
    expect($result[2])->toBe('elif [ $x -eq 2 ]; then');
    expect($result[3])->toBe('    echo "two"');
    expect($result[4])->toBe('else');
    expect($result[5])->toBe('    echo "other"');
    expect($result[6])->toBe('fi');
});

test('handles real-world proxy startup with for loop from StartProxy action', function () {
    // This is the exact command structure that was causing the bug in issue #7346
    $commands = collect([
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
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    // Verify the for loop line doesn't have sudo prefix
    expect($result[5])->toBe('    for i in {1..10}; do');
    expect($result[5])->not->toContain('sudo for');

    // Verify the done line doesn't have sudo prefix
    expect($result[11])->toBe('    done');
    expect($result[11])->not->toContain('sudo done');

    // Verify break doesn't have sudo prefix
    expect($result[7])->toBe('            break');
    expect($result[7])->not->toContain('sudo break');

    // Verify comment doesn't have sudo prefix
    expect($result[4])->toBe('    # Wait for container to be fully removed');
    expect($result[4])->not->toContain('sudo #');

    // Verify other control structures remain correct
    expect($result[0])->toStartWith('if sudo docker ps');
    expect($result[8])->toBe('        fi');
    expect($result[13])->toBe('fi');
});

test('skips sudo for break and continue keywords', function () {
    $commands = collect([
        'for i in {1..5}; do',
        '    if [ $i -eq 3 ]; then',
        '        break',
        '    fi',
        '    if [ $i -eq 2 ]; then',
        '        continue',
        '    fi',
        'done',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[2])->toBe('        break');
    expect($result[2])->not->toContain('sudo');
    expect($result[5])->toBe('        continue');
    expect($result[5])->not->toContain('sudo');
});

test('skips sudo for comment lines starting with #', function () {
    $commands = collect([
        '# This is a comment',
        '    # Indented comment',
        'apt-get update',
        '# Another comment',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('# This is a comment');
    expect($result[0])->not->toContain('sudo');
    expect($result[1])->toBe('    # Indented comment');
    expect($result[1])->not->toContain('sudo');
    expect($result[2])->toBe('sudo apt-get update');
    expect($result[3])->toBe('# Another comment');
    expect($result[3])->not->toContain('sudo');
});

test('skips sudo for until loop keywords', function () {
    $commands = collect([
        'until [ -f /tmp/ready ]; do',
        '    echo "Waiting..."',
        '    sleep 1',
        'done',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('until [ -f /tmp/ready ]; do');
    expect($result[0])->not->toContain('sudo until');
    expect($result[1])->toBe('    echo "Waiting..."');
    // Note: sudo is added at the start of line (before indentation) for non-excluded commands
    expect($result[2])->toBe('sudo     sleep 1');
    expect($result[3])->toBe('done');
});

test('skips sudo for select loop keywords', function () {
    $commands = collect([
        'select opt in "Option1" "Option2"; do',
        '    echo $opt',
        '    break',
        'done',
    ]);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('select opt in "Option1" "Option2"; do');
    expect($result[0])->not->toContain('sudo select');
    expect($result[2])->toBe('    break');
});

// Tests for word boundary matching - ensuring commands are not confused with bash keywords

test('adds sudo for ifconfig command (not confused with if keyword)', function () {
    $commands = collect(['ifconfig eth0']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo ifconfig eth0');
    expect($result[0])->not->toContain('if sudo');
});

test('adds sudo for ifup command (not confused with if keyword)', function () {
    $commands = collect(['ifup eth0']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo ifup eth0');
});

test('adds sudo for ifdown command (not confused with if keyword)', function () {
    $commands = collect(['ifdown eth0']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo ifdown eth0');
});

test('adds sudo for find command (not confused with fi keyword)', function () {
    $commands = collect(['find /var -name "*.log"']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo find /var -name "*.log"');
});

test('adds sudo for file command (not confused with fi keyword)', function () {
    $commands = collect(['file /tmp/test']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo file /tmp/test');
});

test('adds sudo for finger command (not confused with fi keyword)', function () {
    $commands = collect(['finger user']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo finger user');
});

test('adds sudo for docker command (not confused with do keyword)', function () {
    $commands = collect(['docker ps']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo docker ps');
});

test('adds sudo for fortune command (not confused with for keyword)', function () {
    $commands = collect(['fortune']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo fortune');
});

test('adds sudo for formail command (not confused with for keyword)', function () {
    $commands = collect(['formail -s procmail']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('sudo formail -s procmail');
});

test('if keyword with word boundary gets sudo inserted correctly', function () {
    $commands = collect(['if [ -f /tmp/test ]; then']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('if sudo [ -f /tmp/test ]; then');
});

test('fi keyword with word boundary is not given sudo', function () {
    $commands = collect(['fi']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('fi');
});

test('for keyword with word boundary is not given sudo', function () {
    $commands = collect(['for i in 1 2 3; do']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('for i in 1 2 3; do');
});

test('do keyword with word boundary is not given sudo', function () {
    $commands = collect(['do']);

    $result = parseCommandsByLineForSudo($commands, $this->server);

    expect($result[0])->toBe('do');
});
