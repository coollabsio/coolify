<?php

use Symfony\Component\Process\Process;

require_once __DIR__.'/../../bootstrap/helpers/docker.php';

function runRailpackLockScript(string $command, string $setup = ''): Process
{
    $directory = sys_get_temp_dir().'/railpack-lock-'.bin2hex(random_bytes(8));
    mkdir($directory);

    try {
        $script = str_replace('/root/.docker/buildx/coolify-railpack.lock', $directory.'/builder.lock', $setup.railpackBuilderLockedScript($command));
        $process = new Process(['bash', '-c', $script]);
        $process->run();

        return $process;
    } finally {
        @unlink($directory.'/builder.lock');
        rmdir($directory);
    }
}

test('a failed shared lock prevents the builder command from running', function () {
    $process = runRailpackLockScript('printf build-ran', 'flock() { return 73; }; ');

    expect($process->getExitCode())->toBe(73);
    expect($process->getOutput())->toBe('');
});

test('the builder stops after a failed command without hiding its exit status', function () {
    $process = runRailpackLockScript('(exit 42)', 'exec 3>&1; docker() { printf stopped >&3; return 0; }; ');

    expect($process->getExitCode())->toBe(42);
    expect($process->getOutput())->toBe('stopped');
});

test('a held shared lock prevents stopping the builder', function () {
    $process = runRailpackLockScript('printf built', 'exec 8>/root/.docker/buildx/coolify-railpack.lock; flock -s 8; docker() { exit 99; }; ');

    expect($process->getExitCode())->toBe(0);
    expect($process->getOutput())->toBe('built');
});
