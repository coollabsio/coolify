<?php

use App\Livewire\Boarding\Index as BoardingIndex;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create(['custom_server_limit' => 1]);
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->token = $this->user->createToken('write', ['write']);
    $this->token->accessToken->forceFill(['team_id' => $this->team->id])->save();
});

it('rejects an API server when the cloud team is at its limit', function () {
    Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);

    $this->withToken($this->token->plainTextToken)->postJson('/api/v1/servers', [
        'name' => 'Over limit',
        'ip' => '192.0.2.100',
        'private_key_uuid' => $this->privateKey->uuid,
    ])->assertStatus(400)->assertJsonPath('message', 'Server limit reached for your subscription.');

    expect(Server::where('team_id', $this->team->id)->count())->toBe(1);
});

it('allows an API server while the cloud team has capacity', function () {
    $this->withToken($this->token->plainTextToken)->postJson('/api/v1/servers', [
        'name' => 'Within limit',
        'ip' => '192.0.2.104',
        'private_key_uuid' => $this->privateKey->uuid,
    ])->assertCreated();

    expect(Server::where('team_id', $this->team->id)->count())->toBe(1);
});

it('rejects an onboarding server when the cloud team is at its limit', function () {
    Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);

    Livewire::actingAs($this->user)->test(BoardingIndex::class)
        ->set('remoteServerName', 'Over limit')
        ->set('remoteServerHost', '192.0.2.101')
        ->set('remoteServerPort', 22)
        ->set('remoteServerUser', 'root')
        ->set('privateKey', $this->privateKey->private_key)
        ->set('selectedExistingPrivateKey', $this->privateKey->id)
        ->call('saveServer')
        ->assertDispatched('error');

    expect(Server::where('team_id', $this->team->id)->count())->toBe(1);
});

it('does not apply the cloud limit to self-hosted teams', function () {
    config()->set('constants.coolify.self_hosted', true);
    Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);

    $this->withToken($this->token->plainTextToken)->postJson('/api/v1/servers', [
        'name' => 'Self-hosted server',
        'ip' => '192.0.2.102',
        'private_key_uuid' => $this->privateKey->uuid,
    ])->assertCreated();

    expect(Server::where('team_id', $this->team->id)->count())->toBe(2);
});

it('does not let a team member create a server through the API', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $token = $member->createToken('write', ['write']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();

    $this->withToken($token->plainTextToken)->postJson('/api/v1/servers', [
        'name' => 'Member server',
        'ip' => '192.0.2.103',
        'private_key_uuid' => $this->privateKey->uuid,
    ])->assertForbidden();

    expect(Server::where('team_id', $this->team->id)->count())->toBe(0);
});
