<?php

use App\Livewire\Terminal\Index;
use App\Models\Server;
use Illuminate\Support\Collection;

function terminalServer(string $uuid, string $name, array $containers): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->forceFill(['uuid' => $uuid, 'name' => $name]);
    $server->shouldReceive('isFunctional')->once()->andReturnTrue();
    $server->shouldReceive('loadAllContainers')->once()->andReturn(collect($containers));

    return $server;
}

it('keeps containers with the same name on different servers as distinct terminal targets', function () {
    $component = new Index;
    $component->servers = new Collection([
        terminalServer('pulse-uuid', 'Pulse', [
            ['Names' => 'coolify-proxy', 'State' => 'running'],
            ['Names' => 'coolify-sentinel', 'State' => 'running'],
        ]),
        terminalServer('forge-uuid', 'Forge', [
            ['Names' => 'coolify-proxy', 'State' => 'running'],
            ['Names' => 'coolify-sentinel', 'State' => 'running'],
        ]),
    ]);

    $component->loadContainers();

    expect($component->containers)->toHaveCount(4)
        ->and(collect($component->containers)->pluck('uuid')->all())->toBe([
            'pulse-uuid:coolify-proxy',
            'forge-uuid:coolify-proxy',
            'pulse-uuid:coolify-sentinel',
            'forge-uuid:coolify-sentinel',
        ]);
});
