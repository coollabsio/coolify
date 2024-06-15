<?php

namespace Tests\Unit\App\Services\Remote;

use App\Domain\Remote\Commands\RemoteCommand;
use App\Models\Server;
use App\Services\Remote\RemoteCommandService;
use RuntimeException;

beforeEach(function () {
    $this->remoteCommandService = new RemoteCommandService();
    $this->server = Server::factory()->create();

});

it('should throw exception because command is not a RemoteCommand', function () {
    $this->remoteCommandService->executeRemoteCommand($this->server, ['command' => 'ls']);
})->throws(RuntimeException::class, 'Command is not an instance of App\Domain\Remote\Commands\RemoteCommand');

it('should throw exception because command is not set', function () {
    $this->remoteCommandService->executeRemoteCommand($this->server, [new RemoteCommand('')]);
})->throws(RuntimeException::class, 'Command is not set');
