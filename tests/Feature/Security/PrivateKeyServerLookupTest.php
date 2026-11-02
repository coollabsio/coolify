<?php

use App\Livewire\Security\PrivateKey\Show;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    Config::set('cache.default', 'array');
    Config::set('constants.ssh.mux_enabled', true);
    Storage::fake('ssh-keys');
    Process::fake();

    $this->privateKey = teamKey($this->team);
});

function teamToken(User $user, Team $team): string
{
    $token = $user->createToken('test', ['write']);
    $token->accessToken->forceFill(['team_id' => $team->id])->save();
    auth()->forgetGuards();

    return $token->plainTextToken;
}

function serverForKey(PrivateKey $privateKey, bool $deleted = false): Server
{
    $server = Server::factory()->create([
        'team_id' => $privateKey->team_id,
        'private_key_id' => $privateKey->id,
    ]);
    if ($deleted) {
        $server->delete();
    }

    return $server;
}

function teamKey(Team $team): PrivateKey
{
    return PrivateKey::factory()->create([
        'team_id' => $team->id,
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);
}

function assertMuxStops(Server $server, int $times): void
{
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'ssh -O stop')
        && str_contains($process->command, 'mux_'.$server->uuid), $times);
}

test('a key used by one or many active servers is in use', function () {
    serverForKey($this->privateKey);
    expect($this->privateKey->isInUse())->toBeTrue();

    serverForKey($this->privateKey);
    expect($this->privateKey->servers()->count())->toBe(2)
        ->and($this->privateKey->isInUse())->toBeTrue();
});

test('a key used only by soft-deleted servers is not in use and can be deleted', function () {
    serverForKey($this->privateKey, deleted: true);

    expect($this->privateKey->servers()->exists())->toBeFalse()
        ->and($this->privateKey->safeDelete())->toBeTrue()
        ->and(PrivateKey::query()->whereKey($this->privateKey->id)->exists())->toBeFalse();
});

test('an unreferenced key is not in use', function () {
    serverForKey(teamKey($this->team));

    expect($this->privateKey->isInUse())->toBeFalse();
});

test('a key with id 0 is matched like any other key', function () {
    $instanceKey = PrivateKey::factory()->make([
        'team_id' => $this->team->id,
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);
    $instanceKey->id = 0;
    $instanceKey->save();

    expect($instanceKey->isInUse())->toBeFalse();

    serverForKey($instanceKey);

    expect($instanceKey->isInUse())->toBeTrue()
        ->and($instanceKey->safeDelete())->toBeFalse();
});

test('a key without an id matches no servers', function () {
    serverForKey($this->privateKey);

    expect((new PrivateKey)->isInUse())->toBeFalse();

    refresh_server_connection(null);
    refresh_server_connection(new PrivateKey);

    Process::assertNothingRan();
});

test('refreshing connections stops the mux of every active server of the key only', function () {
    $first = serverForKey($this->privateKey);
    $second = serverForKey($this->privateKey);
    $deleted = serverForKey($this->privateKey, deleted: true);
    $other = serverForKey(teamKey($this->team));

    refresh_server_connection($this->privateKey);

    assertMuxStops($first, 1);
    assertMuxStops($second, 1);
    assertMuxStops($deleted, 0);
    assertMuxStops($other, 0);
});

test('changing the key content refreshes each server connection once', function () {
    $server = serverForKey($this->privateKey);
    $newKey = PrivateKey::generateNewKeyPair('ed25519')['private_key'];

    Livewire::test(Show::class, ['private_key_uuid' => $this->privateKey->uuid])
        ->set('privateKeyValue', $newKey)
        ->call('changePrivateKey')
        ->assertDispatched('success');

    expect($this->privateKey->fresh()->private_key)->toBe(formatPrivateKey($newKey));
    assertMuxStops($server, 1);
});

test('a team member cannot change a key used by servers', function () {
    $server = serverForKey($this->privateKey);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(Show::class, ['private_key_uuid' => $this->privateKey->uuid])
        ->set('privateKeyValue', PrivateKey::generateNewKeyPair('ed25519')['private_key'])
        ->call('changePrivateKey');

    expect($this->privateKey->fresh()->private_key)->toBe($this->privateKey->private_key);
    assertMuxStops($server, 0);
});

test('the api refuses to delete a key used by an active server of the team', function () {
    serverForKey($this->privateKey);
    $this->withToken(teamToken($this->user, $this->team))
        ->deleteJson("/api/v1/security/keys/{$this->privateKey->uuid}")
        ->assertStatus(422);

    expect(PrivateKey::query()->whereKey($this->privateKey->id)->exists())->toBeTrue();
});

test('the api does not reveal keys of another team', function () {
    $otherKey = teamKey(Team::factory()->create());
    $this->withToken(teamToken($this->user, $this->team))
        ->deleteJson("/api/v1/security/keys/{$otherKey->uuid}")
        ->assertNotFound();

    expect(PrivateKey::query()->whereKey($otherKey->id)->exists())->toBeTrue();
});
