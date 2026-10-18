<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id, 'user' => 'root']);
    Process::fake();
});

function closesServerMux(Server $server): Closure
{
    return fn ($process) => str_contains($process->command, 'ssh -O stop') && str_contains($process->command, "mux_{$server->uuid}");
}

it('closes the multiplexed SSH connection when the connection details change', function (array $changes) {
    $this->server->update($changes);

    Process::assertRan(closesServerMux($this->server));
})->with([
    'user' => [['user' => 'deploy']],
    'ip' => [['ip' => '203.0.113.20']],
    'port' => [['port' => 2222]],
]);

it('keeps the multiplexed SSH connection when other server fields change', function () {
    $this->server->update(['name' => 'renamed', 'description' => 'changed']);

    Process::assertDidntRun(closesServerMux($this->server));
});
