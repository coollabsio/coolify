<?php

it('checks docker compose up exit status and records failure before claiming upgrade complete', function (string $path) {
    $script = file_get_contents(getcwd().'/'.$path);
    if ($script === false) {
        throw new RuntimeException("Unable to read {$path}");
    }

    $position = static function (string $needle) use ($script): int {
        $offset = strpos($script, $needle);
        if ($offset === false) {
            throw new RuntimeException("Missing marker: {$needle}");
        }

        return $offset;
    };

    $ifComposePosition = $position('if docker run -v /data/coolify/source:/data/coolify/source');
    $composeUpPosition = $position('docker compose --env-file /data/coolify/source/.env');
    $successLogPosition = $position("log 'Docker compose up completed'");
    $errorLogPosition = $position("log 'ERROR: Docker compose up failed'");
    $errorStatusPosition = $position("write_status 'error' 'Docker compose up failed'");
    $exitPosition = strpos($script, 'exit 1', $errorStatusPosition);
    $upgradeCompletePosition = $position("log 'Step 6/6: Upgrade complete'");
    $completedSuccessfullyPosition = $position("log 'Coolify upgrade completed successfully'");

    expect($exitPosition)->not->toBeFalse()
        ->and($ifComposePosition)->toBeLessThan($composeUpPosition)
        ->and($composeUpPosition)->toBeLessThan($successLogPosition)
        ->and($composeUpPosition)->toBeLessThan($errorLogPosition)
        ->and($errorLogPosition)->toBeLessThan($errorStatusPosition)
        ->and($errorStatusPosition)->toBeLessThan($exitPosition)
        ->and($exitPosition)->toBeLessThan($upgradeCompletePosition)
        ->and($successLogPosition)->toBeLessThan($upgradeCompletePosition)
        ->and($upgradeCompletePosition)->toBeLessThan($completedSuccessfullyPosition);

    // Success path and Upgrade complete must stay behind the exit-status guard.
    expect(substr_count($script, "write_status 'error' 'Docker compose up failed'"))->toBe(1)
        ->and(substr_count($script, "log 'ERROR: Docker compose up failed'"))->toBe(1);
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('keeps valid bash syntax after compose exit-status guard', function () {
    foreach (['scripts/upgrade.sh', 'other/nightly/upgrade.sh'] as $path) {
        $process = proc_open(
            ['bash', '-n', getcwd().'/'.$path],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            getcwd()
        );

        expect($process)->toBeResource();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        expect($exitCode, trim($stdout."\n".$stderr))->toBe(0);
    }
});
