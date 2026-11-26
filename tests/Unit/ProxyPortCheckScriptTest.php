<?php

use App\Actions\Proxy\CheckProxy;
use Illuminate\Process\ProcessResult;
use Symfony\Component\Process\Process;

/**
 * Runs the real port check script with fake `ss`, `netstat`, and `docker` commands on PATH
 * and returns whether CheckProxy reports a conflict.
 *
 * @param  array<string, string>  $fakeCommands  command name => stdout
 * @param  list<string>  $hostCommands  host commands to put on PATH
 */
function portCheckReportsConflict(int $port, array $fakeCommands, array $hostCommands = ['grep']): bool
{
    $bin = sys_get_temp_dir().'/proxy-port-check-'.bin2hex(random_bytes(6));
    mkdir($bin);
    foreach ($hostCommands as $name) {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($name)));
        if ($path !== '') {
            symlink($path, "$bin/$name");
        }
    }
    foreach ($fakeCommands as $name => $stdout) {
        file_put_contents("$bin/$name", "#!/bin/sh\nprintf '%s\\n' ".escapeshellarg($stdout)."\n");
        chmod("$bin/$name", 0755);
    }

    $action = new CheckProxy;
    $script = (new ReflectionMethod($action, 'buildPortCheckScript'))->invoke($action, $port, 'coolify-proxy');
    $process = new Process(['/bin/sh', '-c', $script], env: ['PATH' => $bin]);
    $process->run();
    exec('rm -rf '.escapeshellarg($bin));

    return (new ReflectionMethod($action, 'parsePortCheckResult'))->invoke($action, new ProcessResult($process));
}

it('reports a port that another Docker container publishes as a conflict', function (array $fakeCommands) {
    expect(portCheckReportsConflict(18080, ['docker' => ''] + $fakeCommands))->toBeTrue();
})->with([
    'ss' => [['ss' => "tcp 0      4096   0.0.0.0:18080 0.0.0.0:*\ntcp 0      4096      [::]:18080    [::]:*"]],
    'netstat' => [['netstat' => "tcp        0      0 0.0.0.0:18080           0.0.0.0:*               LISTEN\ntcp6       0      0 :::18080                :::*                    LISTEN"]],
]);

it('reports a free port as free', function (array $fakeCommands) {
    expect(portCheckReportsConflict(18080, ['docker' => ''] + $fakeCommands))->toBeFalse();
})->with([
    'ss' => [['ss' => '']],
    'netstat' => [['netstat' => 'tcp        0      0 0.0.0.0:22              0.0.0.0:*               LISTEN']],
]);

it('does not report the port of the own proxy container as a conflict', function () {
    expect(portCheckReportsConflict(18080, [
        'docker' => '{"80/tcp":[{"HostIp":"0.0.0.0","HostPort":"18080"}],"18080/tcp":[{"HostIp":"0.0.0.0","HostPort":"18080"}]}',
        'ss' => 'tcp 0      4096   0.0.0.0:18080 0.0.0.0:*',
    ]))->toBeFalse();
});

it('detects a listener on any address when neither ss nor netstat is installed', function (string $address, array $hostCommands) {
    $server = stream_socket_server("tcp://$address:0");
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    expect(portCheckReportsConflict($port, ['docker' => ''], $hostCommands))->toBeTrue();

    fclose($server);
    expect(portCheckReportsConflict($port, ['docker' => ''], $hostCommands))->toBeFalse();
})->with([
    'all addresses' => ['0.0.0.0', ['grep', 'cat', 'nc']],
    'one address that is not 127.0.0.1' => ['127.0.0.2', ['grep', 'cat', 'nc']],
    'no nc' => ['0.0.0.0', ['grep', 'cat']],
]);
