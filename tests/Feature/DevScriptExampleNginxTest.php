<?php

use Symfony\Component\Process\Process;

it('documents example nginx connectivity commands', function () {
    $process = new Process(['bash', base_path('scripts/dev.sh'), 'example-nginx', 'help'], base_path());

    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain('ping')
        ->and($process->getOutput())->not->toContain('firewall up')
        ->and($process->getOutput())->not->toContain('firewall down')
        ->and($process->getOutput())->not->toContain('firewall-up')
        ->and($process->getOutput())->not->toContain('firewall-down');
});

it('does not wire example nginx to removed firewall CLI commands', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));

    expect($script)->not->toContain('scripts/dev.sh firewall allow "$src" "$dst" tcp 80')
        ->and($script)->not->toContain('scripts/dev.sh firewall revoke "$src" "$dst" tcp 80');
});
