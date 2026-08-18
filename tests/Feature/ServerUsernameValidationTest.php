<?php

use App\Livewire\Boarding\Index as BoardingIndex;
use App\Livewire\Server\New\ByIp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::withoutEvents(fn () => PrivateKey::forceCreate([
        'uuid' => (string) new Cuid2,
        'name' => 'Test SSH Key',
        'description' => 'Test SSH Key',
        'private_key' => 'test-private-key',
        'team_id' => $this->team->id,
    ]));

    $token = $this->user->createToken('write-token', ['write']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->token = $token->plainTextToken;
});

it('creates a server through the API with a dotted SSH username', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/servers', [
        'name' => 'Dotted User Server',
        'ip' => '192.0.2.10',
        'private_key_uuid' => $this->privateKey->uuid,
        'user' => 'deploy.user',
    ]);

    $response->assertCreated();
    $this->assertDatabaseHas('servers', [
        'ip' => '192.0.2.10',
        'user' => 'deploy.user',
    ]);
});

it('updates a server through the API with a dotted SSH username', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'user' => 'deploy',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Content-Type' => 'application/json',
    ])->patchJson('/api/v1/servers/'.$server->uuid, [
        'user' => 'deploy.user',
    ]);

    $response->assertStatus(201);
    expect($server->fresh()->user)->toBe('deploy.user');
});

it('rejects unsafe SSH usernames when creating a server through the API', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/servers', [
        'name' => 'Unsafe User Server',
        'ip' => '192.0.2.11',
        'private_key_uuid' => $this->privateKey->uuid,
        'user' => 'deploy$user',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.user.0', 'The User may only contain letters, numbers, dots, hyphens, and underscores.');
});

it('rejects unsafe SSH usernames through the API', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Content-Type' => 'application/json',
    ])->patchJson('/api/v1/servers/'.$server->uuid, [
        'user' => 'deploy$user',
    ]);

    $response->assertStatus(422);
    $response->assertJsonStructure(['errors' => ['user']]);
    $response->assertJsonPath('errors.user.0', 'The User may only contain letters, numbers, dots, hyphens, and underscores.');
});

it('allows dotted SSH usernames in the server creation form', function () {
    $this->actingAs($this->user);

    Livewire::test(ByIp::class, [
        'private_keys' => collect([$this->privateKey]),
        'limit_reached' => false,
    ])
        ->set('name', 'Dotted User Server')
        ->set('ip', '192.0.2.20')
        ->set('user', 'deploy.user')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertHasNoErrors(['user']);

    $this->assertDatabaseHas('servers', [
        'ip' => '192.0.2.20',
        'user' => 'deploy.user',
    ]);
});

it('rejects unsafe SSH usernames in the server creation form', function () {
    $this->actingAs($this->user);

    Livewire::test(ByIp::class, [
        'private_keys' => collect([$this->privateKey]),
        'limit_reached' => false,
    ])
        ->set('name', 'Unsafe User Server')
        ->set('ip', '192.0.2.21')
        ->set('user', 'deploy$user')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertHasErrors(['user' => ['regex']]);
});

it('rejects unsafe SSH usernames during onboarding server creation', function () {
    $this->actingAs($this->user);

    Livewire::test(BoardingIndex::class)
        ->set('createdPrivateKey', $this->privateKey)
        ->set('remoteServerName', 'Unsafe User Server')
        ->set('remoteServerHost', '192.0.2.30')
        ->set('remoteServerPort', 22)
        ->set('remoteServerUser', 'deploy$user')
        ->call('saveServer')
        ->assertHasErrors([
            'remoteServerUser' => [
                'regex',
                'The SSH User may only contain letters, numbers, dots, hyphens, and underscores.',
            ],
        ]);
});

it('rejects unsafe SSH usernames during onboarding server validation', function () {
    $this->actingAs($this->user);

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'user' => 'deploy',
    ]);

    Livewire::test(BoardingIndex::class)
        ->set('createdServer', $server)
        ->set('remoteServerPort', 22)
        ->set('remoteServerUser', 'deploy$user')
        ->call('saveAndValidateServer')
        ->assertHasErrors([
            'remoteServerUser' => [
                'regex',
                'The SSH User may only contain letters, numbers, dots, hyphens, and underscores.',
            ],
        ]);
});
