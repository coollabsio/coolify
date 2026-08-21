<?php

/**
 * Extracts the hard-minimum disk block from a real install script and runs it in
 * a hermetic harness with a controlled available-space value, so we exercise the
 * shipped guard (arithmetic included) rather than just asserting its source text.
 *
 * @return array{exit: int, stdout: string}
 */
function runInstallGuard(string $scriptRelPath, string $minGb, string $availableGb): array
{
    $script = file_get_contents(getcwd().'/'.$scriptRelPath);
    $start = strpos($script, '# Hard minimum: refuse to install');
    $end = strpos($script, 'if [ "$WARNING_SPACE" = true ]; then');
    if ($start === false || $end === false) {
        throw new RuntimeException("Guard block markers not found in {$scriptRelPath}");
    }
    $block = substr($script, $start, $end - $start);

    $harness = <<<SH
    export MINIMUM_REQUIRED_DISK_GB='{$minGb}'
    AVAILABLE_SPACE='{$availableGb}'

    {$block}

    echo GUARD_PASSED
    SH;

    $harnessFile = tempnam(sys_get_temp_dir(), 'install-guard-').'.sh';
    file_put_contents($harnessFile, $harness);

    $proc = proc_open(['bash', $harnessFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    @unlink($harnessFile);

    return ['exit' => $exit, 'stdout' => $stdout];
}

it('ships install scripts with valid bash syntax', function (string $path) {
    $proc = proc_open(['bash', '-n', getcwd().'/'.$path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($proc)->toBeResource();
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($proc), trim($err))->toBe(0);
})->with([
    'stable install' => 'scripts/install.sh',
    'nightly install' => 'other/nightly/install.sh',
]);

it('aborts the install when free space is below the hard minimum', function (string $path) {
    $result = runInstallGuard($path, '5', '3'); // 3GB available, 5GB required

    expect($result['exit'])->toBe(1);
    expect($result['stdout'])
        ->toContain('ERROR: Not enough free disk space to install Coolify safely.')
        ->not->toContain('GUARD_PASSED');
})->with([
    'stable install' => 'scripts/install.sh',
    'nightly install' => 'other/nightly/install.sh',
]);

it('lets the install proceed when free space meets the hard minimum', function (string $path) {
    $result = runInstallGuard($path, '5', '50'); // 50GB available, 5GB required

    expect($result['exit'])->toBe(0);
    expect($result['stdout'])->toContain('GUARD_PASSED');
})->with([
    'stable install' => 'scripts/install.sh',
    'nightly install' => 'other/nightly/install.sh',
]);

it('honors a leading-zero threshold instead of misreading it as octal', function (string $path) {
    $result = runInstallGuard($path, '08', '4'); // 4GB available, 8GB required

    expect($result['exit'])->toBe(1);
    expect($result['stdout'])->toContain('ERROR: Not enough free disk space to install Coolify safely.');
})->with([
    'stable install' => 'scripts/install.sh',
    'nightly install' => 'other/nightly/install.sh',
]);

it('keeps the recommended-space warning before the hard abort', function (string $path) {
    $script = file_get_contents(getcwd().'/'.$path);

    expect($script)->toContain('MINIMUM_REQUIRED_DISK_GB=$((10#$MINIMUM_REQUIRED_DISK_GB))');

    $warningPosition = strpos($script, 'WARNING: Insufficient available disk space!');
    $hardLimitPosition = strpos($script, 'ERROR: Not enough free disk space to install Coolify safely.');
    expect($warningPosition)->toBeLessThan($hardLimitPosition);
})->with([
    'stable install' => 'scripts/install.sh',
    'nightly install' => 'other/nightly/install.sh',
]);
