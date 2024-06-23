<?php

use App\Domain\Deployment\DeploymentOutput;

it('is able to create a new instance', function () {
    $output = new DeploymentOutput();

    expect($output)->toBeInstanceOf(DeploymentOutput::class);
});

it('is able to get the array on an empty instance', function () {
    $output = new DeploymentOutput();

    expect($output->toArray())->toBe([
        'command' => '',
        'output' => '',
        'type' => 'stdout',
        'hidden' => false,
        'batch' => 1,
        'timestamp' => $output->getTimestamp(),
        'order' => 1,
    ]);
});

it('is able to set the right properties', function () {

    $output = new DeploymentOutput(
        command: 'command',
        output: 'output',
        type: 'stderr',
        hidden: true,
        batch: 9000
    );

    expect($output->toArray())->toBe([
        'command' => 'command',
        'output' => 'output',
        'type' => 'stderr',
        'hidden' => true,
        'batch' => 9000,
        'timestamp' => $output->getTimestamp(),
        'order' => 1,
    ]);
});
