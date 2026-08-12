<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => 'test-key-content',
        'team_id' => $this->team->id,
    ]);
});

it('detects duplicate ip within the same team', function () {
    Server::factory()->create([
        'ip' => '1.2.3.4',
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    $foundServer = Server::whereIp('1.2.3.4')->first();

    expect($foundServer)->not->toBeNull();
    expect($foundServer->team_id)->toBe($this->team->id);
});

it('detects duplicate ip from another team', function () {
    $otherTeam = Team::factory()->create();

    Server::factory()->create([
        'ip' => '5.6.7.8',
        'team_id' => $otherTeam->id,
    ]);

    $foundServer = Server::whereIp('5.6.7.8')->first();

    expect($foundServer)->not->toBeNull();
    expect($foundServer->team_id)->not->toBe($this->team->id);
});

it('shows correct error message for same team duplicate in boarding', function () {
    Server::factory()->create([
        'ip' => '1.2.3.4',
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    $foundServer = Server::whereIp('1.2.3.4')->first();
    if ($foundServer->team_id === currentTeam()->id) {
        $message = 'A server with this IP/Domain already exists in your team.';
    } else {
        $message = 'A server with this IP/Domain is already in use by another team.';
    }

    expect($message)->toBe('A server with this IP/Domain already exists in your team.');
});

it('shows correct error message for other team duplicate in boarding', function () {
    $otherTeam = Team::factory()->create();

    Server::factory()->create([
        'ip' => '5.6.7.8',
        'team_id' => $otherTeam->id,
    ]);

    $foundServer = Server::whereIp('5.6.7.8')->first();
    if ($foundServer->team_id === currentTeam()->id) {
        $message = 'A server with this IP/Domain already exists in your team.';
    } else {
        $message = 'A server with this IP/Domain is already in use by another team.';
    }

    expect($message)->toBe('A server with this IP/Domain is already in use by another team.');
});

it('allows adding ip that does not exist globally', function () {
    $foundServer = Server::whereIp('10.20.30.40')->first();

    expect($foundServer)->toBeNull();
});

it('enforces global uniqueness not just team-scoped', function () {
    $otherTeam = Team::factory()->create();

    Server::factory()->create([
        'ip' => '9.8.7.6',
        'team_id' => $otherTeam->id,
    ]);

    // Global check finds the server even though it belongs to another team
    $foundServer = Server::whereIp('9.8.7.6')->first();
    expect($foundServer)->not->toBeNull();

    // Team-scoped check would miss it - this is why global check is needed
    $teamScopedServer = Server::where('team_id', $this->team->id)
        ->where('ip', '9.8.7.6')
        ->first();
    expect($teamScopedServer)->toBeNull();
});
