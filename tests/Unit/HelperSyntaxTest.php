<?php

use Symfony\Component\Process\Process;

it('loads the database helper without PHP warnings', function () {
    $process = new Process([
        PHP_BINARY,
        '-d',
        'display_errors=1',
        '-d',
        'error_reporting=E_ALL',
        '-l',
        __DIR__.'/../../bootstrap/helpers/databases.php',
    ]);

    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('Warning');
});
