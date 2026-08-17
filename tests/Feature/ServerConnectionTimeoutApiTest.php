<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $newToken = $this->user->createToken('write-token', ['write']);
    $newToken->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->token = $newToken->plainTextToken;
});

it('PATCH updates connection_timeout via API', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Content-Type' => 'application/json',
    ])->patchJson('/api/v1/servers/'.$this->server->uuid, [
        'connection_timeout' => 45,
    ]);

    $response->assertStatus(201);
    expect($this->server->settings->fresh()->connection_timeout)->toBe(45);
});

it('PATCH rejects connection_timeout out of range', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Content-Type' => 'application/json',
    ])->patchJson('/api/v1/servers/'.$this->server->uuid, [
        'connection_timeout' => 0,
    ]);

    $response->assertStatus(422);
    $response->assertJsonStructure(['errors' => ['connection_timeout']]);
});

it('PATCH rejects connection_timeout above max', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Content-Type' => 'application/json',
    ])->patchJson('/api/v1/servers/'.$this->server->uuid, [
        'connection_timeout' => 999,
    ]);

    $response->assertStatus(422);
    $response->assertJsonStructure(['errors' => ['connection_timeout']]);
});

it('PATCH rejects non-integer connection_timeout', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Content-Type' => 'application/json',
    ])->patchJson('/api/v1/servers/'.$this->server->uuid, [
        'connection_timeout' => 'fast',
    ]);

    $response->assertStatus(422);
    $response->assertJsonStructure(['errors' => ['connection_timeout']]);
});
