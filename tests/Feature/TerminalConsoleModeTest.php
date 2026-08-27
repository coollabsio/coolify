<?php

use App\Livewire\Project\Shared\Terminal;
use App\Models\Server;

function consoleModeServer(bool $nonRoot): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isNonRoot')->andReturn($nonRoot);

    return $server;
}

it('builds an interactive attach command with safe detach keys for console mode', function () {
    $command = (new Terminal)->buildDockerCommand(consoleModeServer(false), 'minecraft-abc123', 'attach');

    expect($command)->toBe('docker attach --detach-keys="ctrl-p,ctrl-q" --sig-proxy=false \'minecraft-abc123\'');
});

it('prefixes sudo for non-root servers in console mode', function () {
    $command = (new Terminal)->buildDockerCommand(consoleModeServer(true), 'minecraft-abc123', 'attach');

    expect($command)->toStartWith('sudo docker attach --detach-keys="ctrl-p,ctrl-q" --sig-proxy=false ');
});

it('keeps the interactive shell command for shell mode', function () {
    $command = (new Terminal)->buildDockerCommand(consoleModeServer(false), 'web-1', 'shell');

    expect($command)
        ->toContain("docker exec -it 'web-1' sh -c")
        ->not->toContain('docker attach');
});

it('defaults to console mode when the container keeps stdin open', function () {
    $terminal = new Terminal;
    $terminal->attachAvailable = true;

    expect($terminal->resolveMode(null))->toBe('attach');
});

it('defaults to shell mode when the container has no open stdin', function () {
    $terminal = new Terminal;
    $terminal->attachAvailable = false;

    expect($terminal->resolveMode(null))->toBe('shell');
});

it('never selects console mode when attach is unavailable even if requested', function () {
    $terminal = new Terminal;
    $terminal->attachAvailable = false;

    expect($terminal->resolveMode('attach'))->toBe('shell');
});
