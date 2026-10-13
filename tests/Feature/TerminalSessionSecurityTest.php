<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\TerminalSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::clear();
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function terminalTokenPayload(User $user, Team $team, Server $server, ?string $container = null): array
{
    return [
        'user_id' => $user->id,
        'team_id' => $team->id,
        'server_uuid' => $server->uuid,
        'container' => $container,
    ];
}

it('issues an opaque terminal token without exposing connection data', function () {
    $server = Server::factory()->make([
        'uuid' => 'server-uuid',
        'ip' => '192.0.2.10',
        'team_id' => $this->team->id,
    ]);

    $token = app(TerminalSessionService::class)->issue($this->user, $server);

    expect($token)->toHaveLength(64)
        ->not->toContain($server->uuid)
        ->not->toContain($server->ip);
});

it('rejects expired and replayed terminal tokens', function () {
    $service = app(TerminalSessionService::class);

    expect(fn () => $service->redeem($this->user, str_repeat('a', 64)))
        ->toThrow(AccessDeniedHttpException::class);

    Cache::put('terminal-session:'.str_repeat('b', 64), ['invalid' => true], now()->addMinute());

    expect(fn () => $service->redeem($this->user, str_repeat('b', 64)))
        ->toThrow(AccessDeniedHttpException::class)
        ->and(Cache::has('terminal-session:'.str_repeat('b', 64)))->toBeFalse();
});

it('rejects a cross-team server after token issue', function () {
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);
    $token = str_repeat('c', 64);
    Cache::put("terminal-session:{$token}", terminalTokenPayload($this->user, $this->team, $server), now()->addMinute());

    $this->postJson('/terminal/session', ['token' => $token])->assertNotFound();
});

it('rejects a server that references another teams private key', function () {
    $otherTeam = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $otherTeam->id]);
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $token = str_repeat('d', 64);
    Cache::put("terminal-session:{$token}", terminalTokenPayload($this->user, $this->team, $server), now()->addMinute());

    $this->postJson('/terminal/session', ['token' => $token])->assertForbidden();
});

it('constructs fixed ssh arguments from the authorized server record', function () {
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create([
        'ip' => '192.0.2.25',
        'user' => 'root',
        'port' => 2222,
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $token = app(TerminalSessionService::class)->issue($this->user, $server);

    $response = $this->postJson('/terminal/session', ['token' => $token]);

    $response->assertSuccessful();
    expect($response->json('command'))
        ->toContain("ssh_key@{$privateKey->uuid}")
        ->toContain("'2222'")
        ->toContain("'root'@'192.0.2.25'")
        ->not->toContain('ProxyCommand=');

    $this->postJson('/terminal/session', ['token' => $token])->assertForbidden();
});

it('rejects option-like and traversal container identifiers before command construction', function (string $container) {
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $token = str_repeat('g', 63).random_int(0, 9);
    Cache::put("terminal-session:{$token}", terminalTokenPayload($this->user, $this->team, $server, $container), now()->addMinute());

    $this->postJson('/terminal/session', ['token' => $token])->assertForbidden();
})->with(['-oProxyCommand=id', '../other-container', 'container name']);

it('denies members from redeeming terminal tokens', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->postJson('/terminal/session', ['token' => str_repeat('e', 64)])->assertForbidden();
});

it('does not accept ssh arguments or identity paths from the client', function () {
    $this->postJson('/terminal/session', [
        'token' => str_repeat('f', 64),
        'sshArgs' => ['-o', 'ProxyCommand=id'],
        'identityFile' => '../../other-team-key',
        'target' => '-oProxyCommand=id',
    ])->assertForbidden();
});
