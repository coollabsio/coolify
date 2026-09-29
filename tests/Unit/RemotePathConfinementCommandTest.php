<?php

use App\Models\LocalFileVolume;
use App\Models\Server;
use Symfony\Component\Process\Process;

/**
 * Creates this tree in a temporary directory:
 *   base/existing.conf, base/nested/, outside/secret.conf
 *   base/inner-link -> base/nested
 *   base/escape-dir -> outside
 *   base/escape-file -> outside/secret.conf
 *   base/dangling -> outside/missing.conf
 *   base-link -> base
 */
function makeRemotePathConfinementTree(): string
{
    $root = sys_get_temp_dir().'/coolify-confinement-'.bin2hex(random_bytes(4));
    mkdir($root.'/base/nested', 0755, true);
    mkdir($root.'/outside');
    file_put_contents($root.'/base/existing.conf', 'inside');
    file_put_contents($root.'/outside/secret.conf', 'outside');
    symlink($root.'/base/nested', $root.'/base/inner-link');
    symlink($root.'/outside', $root.'/base/escape-dir');
    symlink($root.'/outside/secret.conf', $root.'/base/escape-file');
    symlink($root.'/outside/missing.conf', $root.'/base/dangling');
    symlink($root.'/base', $root.'/base-link');

    // bin-busybox has only BusyBox tools (like Alpine); both bin directories have a sudo that runs its arguments.
    mkdir($root.'/bin-gnu');
    mkdir($root.'/bin-busybox');
    foreach (['bin-gnu', 'bin-busybox'] as $binaries) {
        file_put_contents("{$root}/{$binaries}/sudo", "#!/bin/sh\nexec \"\$@\"\n");
        chmod("{$root}/{$binaries}/sudo", 0755);
    }
    symlink('/bin/bash', $root.'/bin-busybox/bash');
    foreach (['sh', 'readlink', 'realpath'] as $applet) {
        symlink('/usr/bin/busybox', "{$root}/bin-busybox/{$applet}");
    }

    return $root;
}

/**
 * Runs the confinement command like a server would: with GNU or BusyBox tools, as root or
 * through the non-root sudo parser.
 */
function runRemotePathConfinementCommand(string $toolset, string $root, string $base, string $path): Process
{
    $command = LocalFileVolume::remotePathConfinementCommand($root.'/'.$base, $root.'/'.$path);

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

    // Servers run commands with bash when it exists, else with sh.
    $shell = $usesBusyBox && ! $isNonRoot ? ['/usr/bin/busybox', 'sh'] : ['/bin/bash'];
    $process = new Process([...$shell, '-c', $command], env: [
        'PATH' => $usesBusyBox ? $root.'/bin-busybox' : $root.'/bin-gnu:'.getenv('PATH'),
    ]);
    $process->run();

    return $process;
}

afterEach(function () {
    if (isset($this->confinementRoot)) {
        (new Process(['rm', '-rf', $this->confinementRoot]))->run();
    }
    Mockery::close();
});

test('the path check runs as one shell command for root and non-root servers', function () {
    $command = LocalFileVolume::remotePathConfinementCommand('/data/coolify/applications/app', '/data/coolify/applications/app/data');

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $server->shouldReceive('setAttribute')->andReturnSelf();

    expect($command)->toStartWith('sh -c ')
        ->not->toContain('realpath')
        ->and(parseCommandsByLineForSudo(collect([$command]), $server))->toHaveCount(1)
        ->and(parseCommandsByLineForSudo(collect([$command]), $server)[0])->toStartWith("sudo bash -c 'sh -c ");
});

test('the path check accepts paths that stay inside the base directory', function (string $toolset, string $base, string $path) {
    if (str_starts_with($toolset, 'BusyBox') && ! is_executable('/usr/bin/busybox')) {
        $this->markTestSkipped('BusyBox is not installed.');
    }
    $this->confinementRoot = makeRemotePathConfinementTree();

    $process = runRemotePathConfinementCommand($toolset, $this->confinementRoot, $base, $path);

    expect($process->getErrorOutput())->toBe('')
        ->and(trim($process->getOutput()))->toBe('OK');
})->with([
    'GNU as root',
    'BusyBox as root',
    'GNU as non-root',
    'BusyBox as non-root',
])->with([
    'existing file' => ['base', 'base/existing.conf'],
    'missing file in a missing directory' => ['base', 'base/new/dir/app.conf'],
    'the base directory itself' => ['base', 'base'],
    'symlink that stays inside the base' => ['base', 'base/inner-link/app.conf'],
    'base directory that does not exist yet' => ['missing-base', 'missing-base/data/app.conf'],
    'base directory behind a symlink' => ['base-link', 'base-link/existing.conf'],
]);

test('the path check rejects paths that leave the base directory', function (string $toolset, string $path) {
    if (str_starts_with($toolset, 'BusyBox') && ! is_executable('/usr/bin/busybox')) {
        $this->markTestSkipped('BusyBox is not installed.');
    }
    $this->confinementRoot = makeRemotePathConfinementTree();

    $process = runRemotePathConfinementCommand($toolset, $this->confinementRoot, 'base', $path);

    expect(trim($process->getOutput()))->not->toBe('OK');
})->with([
    'GNU as root',
    'BusyBox as root',
    'GNU as non-root',
    'BusyBox as non-root',
])->with([
    'directory symlink that leaves the base' => ['base/escape-dir/secret.conf'],
    'missing file behind a directory symlink that leaves the base' => ['base/escape-dir/new/app.conf'],
    'file symlink that leaves the base' => ['base/escape-file'],
    'dangling symlink' => ['base/dangling'],
    'parent directory in the missing part' => ['base/new/../../outside/secret.conf'],
    'path outside the base' => ['outside/secret.conf'],
    'prefix of the base name' => ['base-other/app.conf'],
]);
