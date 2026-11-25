<?php

use Symfony\Component\Process\Process;

/*
 * The V5 `scripts/dev.sh` (including its `example-nginx` helper) was archived in
 * docs/v5/archive/scripts/dev.sh.txt and replaced by `scripts/dev`. These tests make
 * sure the current dev script does not document or wire the removed firewall CLI.
 */

it('documents dev script commands without the removed firewall commands', function () {
    $process = new Process(['bash', base_path('scripts/dev'), 'help'], base_path());

    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain('./scripts/dev start')
        ->and($process->getOutput())->not->toContain('firewall up')
        ->and($process->getOutput())->not->toContain('firewall down')
        ->and($process->getOutput())->not->toContain('firewall-up')
        ->and($process->getOutput())->not->toContain('firewall-down');
});

it('does not wire the dev script to removed firewall CLI commands', function () {
    expect(file_exists(base_path('scripts/dev.sh')))->toBeFalse();

    $script = file_get_contents(base_path('scripts/dev'));

    expect($script)->not->toContain('firewall allow')
        ->and($script)->not->toContain('firewall revoke');
});
