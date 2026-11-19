<?php

use App\Models\Server;
use Symfony\Component\Process\Process;

afterEach(function () {
    Mockery::close();
});

test('ownership changes do not follow symlinks to files outside the Coolify path', function (string $parser, string $tools) {
    if (posix_geteuid() !== 0 || posix_getpwnam('daemon') === false) {
        $this->markTestSkipped('Needs root and a daemon user to change file owners.');
    }
    if ($tools === 'busybox' && ! is_executable('/usr/bin/busybox')) {
        $this->markTestSkipped('Needs BusyBox.');
    }
    $directory = sys_get_temp_dir().'/coolify-sudo-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents("{$directory}/sudo", "#!/bin/sh\nexec \"\$@\"\n");
    chmod("{$directory}/sudo", 0755);
    if ($tools === 'busybox') {
        foreach (['find', 'chown', 'chmod', 'mkdir'] as $tool) {
            symlink('/usr/bin/busybox', "{$directory}/{$tool}");
        }
    }
    // A root-owned file outside Coolify's directories, like /etc/sudoers.
    $target = "{$directory}/sudoers";
    file_put_contents($target, 'root ALL=(ALL) ALL');
    $path = '/tmp/coolify/symlink-test-'.bin2hex(random_bytes(4));
    mkdir($path, 0700, true);
    symlink($target, "{$path}/link");

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('getAttribute')->with('user')->andReturn('daemon');
    $server->shouldReceive('setAttribute')->andReturnSelf();
    $command = $parser === 'command list'
        ? parseCommandsByLineForSudo(collect(["mkdir -p {$path}"]), $server)[0]
        : parseLineForSudo("mkdir -p {$path}", $server);
    $process = new Process(['/bin/sh', '-c', $command], env: ['PATH' => "{$directory}:".getenv('PATH')]);
    $process->run();
    clearstatcache();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->isSuccessful())->toBeTrue()
        ->and(fileowner($target))->toBe(0)
        ->and(lstat("{$path}/link")['uid'])->toBe(1)
        ->and(fileowner($path))->toBe(1);

    (new Process(['rm', '-rf', $directory, $path]))->run();
})->with(['command list', 'deployment line'])->with(['coreutils', 'busybox']);
