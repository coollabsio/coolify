<?php

use App\Models\Server;
use Tests\Support\Output;

it('starts a docker container correctly', function () {

    $coolifyNamePrefix = 'coolify_test_';
    $format = '{"ID":"{{ .ID }}", "Image": "{{ .Image }}", "Names":"{{ .Names }}"}';
    $areThereCoolifyTestContainers = "docker ps --filter=\"name={$coolifyNamePrefix}*\" --format '{$format}' ";

    // Generate a known name
    $containerName = 'coolify_test_' . now()->format('Ymd_his');
    $host = Server::where('name', 'testing-local-docker-container')->first();

    // Assert there's no containers start with coolify_test_*
    $activity = remoteProcess([$areThereCoolifyTestContainers], $host);
    $containers = formatDockerCmdOutputToJson($activity->getExtraProperty('stdout'));
    expect($containers)->toBeEmpty();

    // start a container nginx -d --name = $containerName
    $activity = remoteProcess(["docker run -d --rm --name {$containerName} nginx"], $host);
    expect($activity->getExtraProperty('exitCode'))->toBe(0);

    // docker ps name = $container
    $activity = remoteProcess([$areThereCoolifyTestContainers], $host);
    $containers = formatDockerCmdOutputToJson($activity->getExtraProperty('stdout'));
    expect($containers->where('Names', $containerName)->count())->toBe(1);

    // Stop testing containers
    $activity = remoteProcess(["docker stop $(docker ps --filter='name={$coolifyNamePrefix}*' -q)"], $host);
    expect($activity->getExtraProperty('exitCode'))->toBe(0);
});
