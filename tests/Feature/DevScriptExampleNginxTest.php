<?php

use Symfony\Component\Process\Process;

it('documents example nginx connectivity and firewall commands', function () {
    $process = new Process(['bash', base_path('scripts/dev.sh'), 'example-nginx', 'help'], base_path());

    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain('ping')
        ->and($process->getOutput())->toContain('firewall up')
        ->and($process->getOutput())->toContain('firewall down')
        ->and($process->getOutput())->not->toContain('firewall-up')
        ->and($process->getOutput())->not->toContain('firewall-down');
});

it('uses the coolify firewall wrapper for example nginx firewall changes', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));

    expect($script)->toContain('scripts/dev.sh firewall allow "$src" "$dst" tcp 80')
        ->and($script)->toContain('scripts/dev.sh firewall revoke "$src" "$dst" tcp 80');
});
