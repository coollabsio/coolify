<?php

it('ships install scripts with valid bash syntax', function (string $path) {
    $process = proc_open(
        ['bash', '-n', getcwd().'/'.$path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        getcwd()
    );

    expect($process)->toBeResource();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process), trim($stdout."\n".$stderr))->toBe(0);
})->with([
    'stable install' => 'scripts/install.sh',
    'nightly install' => 'other/nightly/install.sh',
]);

it('aborts the install when free disk space is below the hard minimum', function (string $path) {
    $script = file_get_contents(getcwd().'/'.$path);

    // Enforced floor (overridable), with a malformed-value fallback so the guard cannot be silently disabled.
    expect($script)
        ->toContain('MINIMUM_REQUIRED_DISK_GB="${MINIMUM_REQUIRED_DISK_GB:-5}"')
        ->toContain("'' | *[!0-9]*) MINIMUM_REQUIRED_DISK_GB=5 ;;")
        ->toContain('[ "$AVAILABLE_SPACE" -lt "$MINIMUM_REQUIRED_DISK_GB" ]')
        ->toContain('ERROR: Not enough free disk space to install Coolify safely.')
        ->toContain('exit 1');

    // The hard abort must come after the recommended-space warnings, not replace them.
    $warningPosition = strpos($script, 'WARNING: Insufficient available disk space!');
    $hardLimitPosition = strpos($script, 'ERROR: Not enough free disk space to install Coolify safely.');

    expect($warningPosition)->not->toBeFalse();
    expect($hardLimitPosition)->not->toBeFalse();
    expect($warningPosition)->toBeLessThan($hardLimitPosition);
})->with([
    'stable install' => 'scripts/install.sh',
    'nightly install' => 'other/nightly/install.sh',
]);
