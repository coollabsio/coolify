<?php

it('provides a local helper build and deployment workflow', function () {
    $script = dirname(__DIR__, 2).'/scripts/dev-helper';

    expect($script)->toBeFile()
        ->and(is_executable($script))->toBeTrue();

    exec('bash -n '.escapeshellarg($script), $output, $exitCode);

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($script))
        ->toContain('build)')
        ->toContain('use)')
        ->toContain('verify)')
        ->toContain('deploy)')
        ->toContain('reset)')
        ->toContain('test)');
});
