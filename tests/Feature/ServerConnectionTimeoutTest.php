<?php

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->team = $user->teams()->first();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
});

it('defaults connection_timeout to 10 seconds for new servers', function () {
    expect($this->server->settings->connection_timeout)->toBe(10);
});

it('persists a custom connection_timeout value', function () {
    $this->server->settings->connection_timeout = 30;
    $this->server->settings->save();

    expect($this->server->settings->fresh()->connection_timeout)->toBe(30);
});

it('returns the per-server connection_timeout from getConnectionTimeout', function () {
    $this->server->settings->connection_timeout = 45;
    $this->server->settings->save();

    expect(SshMultiplexingHelper::getConnectionTimeout($this->server->fresh()))->toBe(45);
});

it('falls back to config default when connection_timeout is invalid', function () {
    $this->server->settings->connection_timeout = 0;
    $this->server->settings->saveQuietly();

    $expected = (int) config('constants.ssh.connection_timeout');

    expect(SshMultiplexingHelper::getConnectionTimeout($this->server->fresh()))->toBe($expected);
});
