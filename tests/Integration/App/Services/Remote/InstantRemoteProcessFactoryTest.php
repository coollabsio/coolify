<?php

use App\Models\Server;
use App\Services\Remote\InstantRemoteProcessFactory;

beforeEach(function () {
    $this->factory = app()->make(InstantRemoteProcessFactory::class);
});

it('is able to create a command', function (bool $nonRootUser, string $serverIp, string $expectedCommand) {

    $server = Server::factory()->create([
        'ip' => $serverIp,
        'user' => $nonRootUser ? 'user' : 'root',
    ]);

    $command = 'ls -la';

    $generatedCommand = $this->factory->generateCommand($server, $command);

    expect($generatedCommand)->toBe($expectedCommand);
})->with([
    'a non root user and local server' => [
        'isNonRoot' => false,
        'ip' => '127.0.0.1',
        'expectedCommand' => 'ls -la',
    ],
    'a root user and local server' => [
        'isNonRoot' => true,
        'ip' => '127.0.0.1',
        'expectedCommand' => 'sudo ls -la',
    ],
]);

it('is able to create a command from a collection', function (bool $nonRootUser, string $serverIp, string $expectedCommand) {

    $server = Server::factory()->create([
        'ip' => $serverIp,
        'user' => $nonRootUser ? 'user' : 'root',
    ]);

    $commands = collect([
        'ls -la &&',
        'cd /var/www/html',
    ]);

    $generatedCommand = $this->factory->generateCommandFromCollection($server, $commands);

    expect($generatedCommand)->toBe($expectedCommand);
})->with([
    'a non root user and local server' => [
        'isNonRoot' => false,
        'ip' => '127.0.0.1',
        'expectedCommand' => "ls -la &&\ncd /var/www/html",
    ],
    'a root user and local server' => [
        'isNonRoot' => true,
        'ip' => '127.0.0.1',
        'expectedCommand' => "sudo ls -la && sudo \ncd /var/www/html",
    ],
]);
