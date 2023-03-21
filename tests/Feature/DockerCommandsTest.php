<?php

use Tests\Support\Output;

it('starts a docker container correctly', function () {

    $coolifyNamePrefix = 'coolify_test_';
    $format = '{"ID":"{{ .ID }}", "Image": "{{ .Image }}", "Names":"{{ .Names }}"}';
    $areThereCoolifyTestContainers = "docker ps --filter=\"name={$coolifyNamePrefix}*\" --format '{$format}' ";

    // Generate a known name
    $containerName = 'coolify_test_' . now()->format('Ymd_his');
    $host = 'testing-host';

    // Assert there's no containers start with coolify_test_*
    $processResult = coolifyProcess($areThereCoolifyTestContainers, $host);
    $containers = Output::containerList($processResult->output());
    expect($containers)->toBeEmpty();

    // start a container nginx -d --name = $containerName
    $processResult = coolifyProcess("docker run -d --name {$containerName} nginx", $host);
    expect($processResult->successful())->toBeTrue();

    // docker ps name = $container
    $processResult = coolifyProcess($areThereCoolifyTestContainers, $host);
    $containers = Output::containerList($processResult->output());
    expect($containers->where('Names', $containerName)->count())->toBe(1);

    // Stop testing containers
    $processResult = coolifyProcess("docker stop $(docker ps --filter='name={$coolifyNamePrefix}*' -q)", $host);
    expect($processResult->successful())->toBeTrue();
});
