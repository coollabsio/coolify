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
    Process::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => ' host.docker.internal ',
        'user' => ' root ',
    ]);
});

it('keeps ip and user as trimmed strings on the same instance after save', function () {
    expect($this->server->id)->not->toBe(0)
        ->and($this->server->ip)->toBe('host.docker.internal')
        ->and($this->server->user)->toBe('root')
        ->and($this->server->isLocalhost())->toBeTrue();

    $this->server->save();

    expect($this->server->ip)->toBe('host.docker.internal')
        ->and($this->server->user)->toBe('root')
        ->and($this->server->isLocalhost())->toBeTrue();
});

it('returns the normalized ip after save, not the raw input', function () {
    $this->server->update(['ip' => ' 2A01:04F8::0001 ']);

    expect($this->server->ip)->toBe('2a01:4f8::1')
        ->and($this->server->fresh()->ip)->toBe('2a01:4f8::1');
});
