<?php

use App\Events\CloudflareTunnelChanged;
use App\Listeners\CloudflareTunnelChangedNotification;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

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

it('uses sudo for the health check on a non-root server', function () {
    Event::fake();
    Sleep::fake();
    Process::fake([
        '*sudo docker inspect coolify-cloudflared*' => Process::result(output: 'true'),
        '*' => Process::result(errorOutput: 'permission denied while trying to connect to the Docker API', exitCode: 1),
    ]);

    (new CloudflareTunnelChangedNotification)->handle(new CloudflareTunnelChanged([
        'server_id' => $this->server->id,
        'ssh_domain' => 'ssh.example.com',
    ]));

    expect($this->server->settings->refresh()->is_cloudflare_tunnel)->toBeTruthy()
        ->and($this->server->refresh()->ip)->toBe('ssh.example.com');
});
