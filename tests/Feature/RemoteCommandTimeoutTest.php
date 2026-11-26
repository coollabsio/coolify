<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config()->set('constants.ssh.max_retries', 1);
    config()->set('constants.ssh.mux_enabled', false);

    $user = User::factory()->create();
    $team = $user->teams()->first();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'user' => 'ubuntu',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
});

it('bounds both docker commands of the container status check on a non-root server', function () {
    fakeRemoteProcesses(['*' => Process::result(output: '')]);

    $this->server->refresh()->getContainers();

    Process::assertRan(fn ($process) => str_contains(
        remoteProcessScript($process),
        "sudo timeout -k 5s 15s docker container inspect $(sudo timeout -k 5s 10s docker container ls -aq) --format '{{json .}}'",
    ));
});

it('bounds both docker commands of the swarm status check on a non-root server', function () {
    $this->server->settings->update(['is_swarm_manager' => true]);
    fakeRemoteProcesses(['*' => Process::result(output: '')]);

    $this->server->refresh()->getContainers();

    Process::assertRan(fn ($process) => str_contains(
        remoteProcessScript($process),
        "sudo timeout -k 5s 15s docker service inspect $(sudo timeout -k 5s 10s docker service ls -q) --format '{{json .}}'",
    ));
});
