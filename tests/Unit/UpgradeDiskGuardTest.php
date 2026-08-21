<?php

/**
 * Extracts the disk-guard block from a real upgrade script and runs it in a
 * hermetic harness with stubbed `df`/`docker`, so we exercise the shipped guard
 * (arithmetic included) rather than just asserting its source text.
 *
 * @return array{exit: int, stdout: string, status: string}
 */
function runUpgradeGuard(string $scriptRelPath, string $minGb, int $availableMb): array
{
    $script = file_get_contents(getcwd().'/'.$scriptRelPath);
    $start = strpos($script, '# Pre-flight: ensure there is enough free disk space');
    $end = strpos($script, 'log_section "Step 1/6');
    if ($start === false || $end === false) {
        throw new RuntimeException("Guard block markers not found in {$scriptRelPath}");
    }
    $block = substr($script, $start, $end - $start);

    $statusFile = tempnam(sys_get_temp_dir(), 'upg-status-');
    $harness = <<<SH
    export MINIMUM_REQUIRED_DISK_GB='{$minGb}'
    ENV_FILE=/dev/null
    STATUS_FILE='{$statusFile}'
    write_status() { echo "\$1|\$2" > "\$STATUS_FILE"; }
    log() { :; }
    # Report {$availableMb}MB available (column 4 of `df -Pm` line 2) for any path.
    df() { printf 'Filesystem 1M-blocks Used Available Use%% Mounted\\nstub 100000 100000 {$availableMb} 50%% /\\n'; }
    # Fail docker info so DockerRootDir resolution falls back to /var/lib/docker.
    docker() { return 1; }

    {$block}

    echo GUARD_PASSED
    SH;

    $harnessFile = tempnam(sys_get_temp_dir(), 'upg-guard-').'.sh';
    file_put_contents($harnessFile, $harness);

    $proc = proc_open(['bash', $harnessFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    $status = trim((string) @file_get_contents($statusFile));

    @unlink($statusFile);
    @unlink($harnessFile);

    return ['exit' => $exit, 'stdout' => $stdout, 'status' => $status];
}

it('ships upgrade scripts with valid bash syntax', function (string $path) {
    $proc = proc_open(['bash', '-n', getcwd().'/'.$path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($proc)->toBeResource();
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($proc), trim($err))->toBe(0);
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('aborts the upgrade with an error status when free space is below the minimum', function (string $path) {
    $result = runUpgradeGuard($path, '3', 1024); // 1GB available, 3GB required

    expect($result['exit'])->toBe(1);
    expect($result['stdout'])->not->toContain('GUARD_PASSED');
    expect($result['status'])->toStartWith('error|');
    expect($result['status'])->toContain('Not enough disk space');
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('lets the upgrade proceed when free space is above the minimum', function (string $path) {
    $result = runUpgradeGuard($path, '3', 50000); // 50GB available, 3GB required

    expect($result['exit'])->toBe(0);
    expect($result['stdout'])->toContain('GUARD_PASSED');
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('honors a leading-zero threshold instead of misreading it as octal', function (string $path) {
    // Without base-10 normalization, `08` breaks the arithmetic and silently
    // disables the guard, so free space below 8GB would wrongly pass.
    $result = runUpgradeGuard($path, '08', 4096); // 4GB available, 8GB required

    expect($result['exit'])->toBe(1);
    expect($result['status'])->toStartWith('error|');
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('falls back to the default when the threshold override is malformed', function (string $path) {
    // Garbage override must not disable the guard: it falls back to 3GB, so 1GB free aborts.
    $result = runUpgradeGuard($path, 'not-a-number', 1024);

    expect($result['exit'])->toBe(1);
    expect($result['status'])->toStartWith('error|');
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('checks the data dir and Docker storage, and runs before pulling images', function (string $path) {
    $script = file_get_contents(getcwd().'/'.$path);

    expect($script)
        ->toContain('MINIMUM_REQUIRED_DISK_GB=$((10#$MINIMUM_REQUIRED_DISK_GB))')
        ->toContain('AVAILABLE_MB=$(available_mb /data/coolify)')
        ->toContain("DOCKER_ROOT=\$(docker info --format '{{.DockerRootDir}}' 2>/dev/null)");

    $guardPosition = strpos($script, 'Not enough disk space to upgrade safely');
    $pullPosition = strpos($script, 'Step 3/6');
    expect($guardPosition)->toBeLessThan($pullPosition);
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);
