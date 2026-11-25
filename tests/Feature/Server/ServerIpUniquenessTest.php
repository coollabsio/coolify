<?php

use App\Livewire\Server\New\ByIp;
use App\Livewire\Server\Show;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Process::fake();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $token = $this->user->createToken('write-token', ['write']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->token = $token->plainTextToken;
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

it('rejects a duplicate ip from the same team when adding a server', function () {
    Server::factory()->create([
        'ip' => '1.2.3.4',
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    Livewire::test(ByIp::class, [
        'private_keys' => collect([$this->privateKey]),
        'limit_reached' => false,
    ])
        ->set('ip', '1.2.3.4')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertDispatched('error', 'A server with this IP/Domain and SSH port already exists.');

    expect(Server::where('ip', '1.2.3.4')->count())->toBe(1);
});

it('rejects a duplicate ip from another team without revealing the owner team', function () {
    $otherTeam = Team::factory()->create();

    Server::factory()->create([
        'ip' => '5.6.7.8',
        'team_id' => $otherTeam->id,
    ]);

    Livewire::test(ByIp::class, [
        'private_keys' => collect([$this->privateKey]),
        'limit_reached' => false,
    ])
        ->set('ip', '5.6.7.8')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertDispatched('error', 'A server with this IP/Domain and SSH port already exists.')
        ->assertNotDispatched('error', 'A server with this IP/Domain is already in use by another team.');

    expect(Server::where('ip', '5.6.7.8')->count())->toBe(1);
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

it('adds a server with the IP of another server and a different SSH port', function () {
    Server::factory()->create([
        'ip' => '1.2.3.4',
        'port' => 22,
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    Livewire::test(ByIp::class, [
        'private_keys' => collect([$this->privateKey]),
        'limit_reached' => false,
    ])
        ->set('name', 'nat-vm-2')
        ->set('ip', '1.2.3.4')
        ->set('port', 2222)
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertNotDispatched('error');

    expect(Server::where('ip', '1.2.3.4')->orderBy('port')->pluck('port')->all())->toBe([22, 2222]);
});

it('rejects a server with the IP and SSH port of a server of another team', function () {
    Server::factory()->create([
        'ip' => '5.6.7.8',
        'port' => 2222,
        'team_id' => Team::factory()->create()->id,
    ]);

    Livewire::test(ByIp::class, [
        'private_keys' => collect([$this->privateKey]),
        'limit_reached' => false,
    ])
        ->set('ip', '5.6.7.8')
        ->set('port', 2222)
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertDispatched('error', 'A server with this IP/Domain and SSH port already exists.');

    expect(Server::where('ip', '5.6.7.8')->count())->toBe(1);
});

it('rejects changing the SSH port of a server to the endpoint of another server', function () {
    Server::factory()->create([
        'ip' => '1.2.3.4',
        'port' => 2222,
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $server = Server::factory()->create([
        'ip' => '1.2.3.4',
        'port' => 22,
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->set('port', '2222')
        ->call('submit')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($server->fresh()->port)->toBe(22);
});

it('creates a server through the API with the IP of another server and a different SSH port', function () {
    Auth::forgetGuards();
    Server::factory()->create([
        'ip' => '1.2.3.4',
        'port' => 22,
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    $payload = [
        'name' => 'nat-vm',
        'ip' => '1.2.3.4',
        'private_key_uuid' => $this->privateKey->uuid,
    ];

    $this->withToken($this->token)->postJson('/api/v1/servers', $payload)
        ->assertBadRequest()
        ->assertJsonPath('message', 'A server with this IP/Domain and SSH port is already in use.');
    $this->withToken($this->token)->postJson('/api/v1/servers', [...$payload, 'port' => 2222])
        ->assertCreated();

    expect(Server::where('ip', '1.2.3.4')->orderBy('port')->pluck('port')->all())->toBe([22, 2222]);
});

it('rejects an API update to the IP and SSH port of another server', function () {
    Auth::forgetGuards();
    Server::factory()->create([
        'ip' => '1.2.3.4',
        'port' => 2222,
        'team_id' => Team::factory()->create()->id,
    ]);
    $server = Server::factory()->create([
        'ip' => '9.9.9.9',
        'port' => 22,
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    $this->withToken($this->token)->patchJson('/api/v1/servers/'.$server->uuid, ['ip' => '1.2.3.4', 'port' => 2222])
        ->assertBadRequest()
        ->assertJsonPath('message', 'A server with this IP/Domain and SSH port is already in use.');
    expect($server->fresh()->only(['ip', 'port']))->toBe(['ip' => '9.9.9.9', 'port' => 22]);

    $this->withToken($this->token)->patchJson('/api/v1/servers/'.$server->uuid, ['ip' => '1.2.3.4'])
        ->assertCreated();
    expect($server->fresh()->ip)->toBe('1.2.3.4');
});
