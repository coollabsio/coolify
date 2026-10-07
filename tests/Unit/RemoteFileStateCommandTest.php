<?php

use App\Models\LocalFileVolume;
use App\Models\Server;
use Symfony\Component\Process\Process;

/**
 * Creates this tree in a temporary directory:
 *   base/existing.conf, base/empty-dir/, base/full-dir/keep.conf, base/with space.conf
 *   base/file-link -> base/existing.conf
 *   base/dir-link -> base/empty-dir
 *   base/dangling -> base/missing-target.conf
 */
function makeRemoteFileStateTree(): string
{
    $root = sys_get_temp_dir().'/coolify-file-state-'.bin2hex(random_bytes(4));
    mkdir($root.'/base/empty-dir', 0755, true);
    mkdir($root.'/base/full-dir');
    file_put_contents($root.'/base/existing.conf', 'inside');
    file_put_contents($root.'/base/with space.conf', 'inside');
    file_put_contents($root.'/base/full-dir/keep.conf', 'keep');
    symlink($root.'/base/existing.conf', $root.'/base/file-link');
    symlink($root.'/base/empty-dir', $root.'/base/dir-link');
    symlink($root.'/base/missing-target.conf', $root.'/base/dangling');

    // bin-busybox has only BusyBox tools (like Alpine); both bin directories have a sudo that runs its arguments.
    mkdir($root.'/bin-gnu');
    mkdir($root.'/bin-busybox');
    foreach (['bin-gnu', 'bin-busybox'] as $binaries) {
        file_put_contents("{$root}/{$binaries}/sudo", "#!/bin/sh\nexec \"\$@\"\n");
        chmod("{$root}/{$binaries}/sudo", 0755);
    }
    symlink('/bin/bash', $root.'/bin-busybox/bash');
    foreach (['sh', 'ls'] as $applet) {
        symlink('/usr/bin/busybox', "{$root}/bin-busybox/{$applet}");
    }

    return $root;
}

/**
 * Runs the state command like a server would: with GNU or BusyBox tools, as root or through the
 * non-root sudo parser.
 *
 * @param  list<string>  $paths
 */
function runRemoteFileStateCommand(string $toolset, string $root, array $paths): Process
{
    $command = LocalFileVolume::remoteFileStateCommand(array_map(fn (string $path) => $root.'/'.$path, $paths));

    [$usesBusyBox, $isNonRoot] = match ($toolset) {
        'GNU as root' => [false, false],
        'BusyBox as root' => [true, false],
        'GNU as non-root' => [false, true],
        'BusyBox as non-root' => [true, true],
    };

    if ($isNonRoot) {
        $server = Mockery::mock(Server::class)->makePartial();
        $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
        $server->shouldReceive('setAttribute')->andReturnSelf();
        $command = parseCommandsByLineForSudo(collect([$command]), $server)[0];
    }

    $shell = $usesBusyBox && ! $isNonRoot ? ['/usr/bin/busybox', 'sh'] : ['/bin/bash'];
    $process = new Process([...$shell, '-c', $command], env: [
        'PATH' => $usesBusyBox ? $root.'/bin-busybox' : $root.'/bin-gnu:'.getenv('PATH'),
    ]);
    $process->run();

    return $process;
}

afterEach(function () {
    if (isset($this->fileStateRoot)) {
        (new Process(['rm', '-rf', $this->fileStateRoot]))->run();
    }
    Mockery::close();
});

test('the file state check is one shell command for root and non-root servers', function () {
    $command = LocalFileVolume::remoteFileStateCommand(['/data/coolify/services/abc/a.conf', '/data/coolify/services/abc/b.conf']);

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $server->shouldReceive('setAttribute')->andReturnSelf();
    $parsed = parseCommandsByLineForSudo(collect([$command]), $server);

    expect($command)->toStartWith('sh -c ')
        ->toEndWith(" sh '/data/coolify/services/abc/a.conf' '/data/coolify/services/abc/b.conf'")
        ->and($parsed)->toHaveCount(1)
        ->and($parsed[0])->toStartWith("sudo bash -c 'sh -c ");
});

test('the file state check reports the state of every path in order', function (string $toolset) {
    if (str_starts_with($toolset, 'BusyBox') && ! is_executable('/usr/bin/busybox')) {
        $this->markTestSkipped('BusyBox is not installed.');
    }
    $this->fileStateRoot = makeRemoteFileStateTree();

    $process = runRemoteFileStateCommand($toolset, $this->fileStateRoot, [
        'base/existing.conf',
        'base/missing.conf',
        'base/empty-dir',
        'base/full-dir',
        'base/file-link',
        'base/dir-link',
        'base/dangling',
        'base/with space.conf',
        'base/missing-dir/app.conf',
    ]);

    expect($process->getErrorOutput())->toBe('')
        ->and(explode("\n", trim($process->getOutput())))->toBe([
            '1:file',
            '2:missing',
            '3:empty-directory',
            '4:directory',
            '5:file',
            '6:other',
            '7:other',
            '8:file',
            '9:missing',
        ]);
})->with([
    'GNU as root',
    'BusyBox as root',
    'GNU as non-root',
    'BusyBox as non-root',
]);

test('the file state check does not change the server', function () {
    $this->fileStateRoot = makeRemoteFileStateTree();

    runRemoteFileStateCommand('GNU as non-root', $this->fileStateRoot, ['base/empty-dir', 'base/full-dir', 'base/missing.conf']);

    expect(is_dir($this->fileStateRoot.'/base/empty-dir'))->toBeTrue()
        ->and(file_exists($this->fileStateRoot.'/base/full-dir/keep.conf'))->toBeTrue()
        ->and(file_exists($this->fileStateRoot.'/base/missing.conf'))->toBeFalse();
});

test('the file state check never runs a path as a command', function () {
    $this->fileStateRoot = makeRemoteFileStateTree();
    $marker = $this->fileStateRoot.'/pwned';

    runRemoteFileStateCommand('GNU as non-root', $this->fileStateRoot, [
        "base/x'; touch {$marker}; '",
        "base/\$(touch {$marker})",
        "base/`touch {$marker}`",
    ]);

    expect(file_exists($marker))->toBeFalse();
});
