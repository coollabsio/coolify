<?php

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\AuditEvent;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\TerminalSessionService;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeRemoteProcesses(['*' => Process::result()]);
    $this->withoutDefer();
    Server::flushIdentityMap();
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

it('carries the console mode in the token and attaches instead of exec on redeem', function () {
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $service = app(TerminalSessionService::class);
    fakeRemoteProcesses([
        '*docker inspect*' => Process::result(output: json_encode(['State' => ['Status' => 'running']])),
        '*' => Process::result(),
    ]);

    $attachCommand = $service->redeem($this->user, $service->issue($this->user, $server, 'minecraft-abc123', 'attach'))['command'];
    $shellCommand = $service->redeem($this->user, $service->issue($this->user, $server, 'minecraft-abc123'))['command'];

    expect($attachCommand)
        ->toContain('docker attach --detach-keys="ctrl-p,ctrl-q" --sig-proxy=false')
        ->not->toContain('docker exec')
        ->and($shellCommand)
        ->toContain('docker exec -it')
        ->not->toContain('docker attach');
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

function activeTerminalSecuritySession(User $user, Team $team): array
{
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    /** @var Server $server */
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id]);
    $service = app(TerminalSessionService::class);

    return [$server, $service->redeem($user, $service->issue($user, $server))['session_id']];
}

it('rejects an active session after administrative access is lost', function () {
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    $service = app(TerminalSessionService::class);
    $service->check($this->user, $id);
    $this->user->teams()->updateExistingPivot($this->team->id, ['role' => 'member']);
    expect(fn () => $service->check($this->user->fresh(), $id))->toThrow(AccessDeniedHttpException::class);
});

it('rejects an active session after server terminal access is disabled', function (string $setting, bool $value) {
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    $server->settings->forceFill([$setting => $value])->saveQuietly();
    expect(fn () => app(TerminalSessionService::class)->check($this->user, $id))->toThrow(AccessDeniedHttpException::class);
})->with(['disabled' => ['is_terminal_enabled', false], 'force disabled' => ['force_disabled', true]]);

it('rejects another users active session', function () {
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'owner']);
    expect(fn () => app(TerminalSessionService::class)->check($other, $id))->toThrow(AccessDeniedHttpException::class);
});

it('audits the end reason and duration only once', function () {
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    $this->travel(42)->seconds();
    $service = app(TerminalSessionService::class);
    $service->end($id, 'revoked');
    $service->end($id, 'exited');
    $events = AuditEvent::where('event', 'terminal.session.ended')->get();
    expect($events)->toHaveCount(1);
    expect($events->first()->metadata)->toMatchArray(['reason' => 'revoked', 'duration_seconds' => 42]);
    expect(fn () => $service->check($this->user, $id))->toThrow(AccessDeniedHttpException::class);
});

function enforceTerminalSecurityCsrf(): void
{
    app()->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends VerifyCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
}

it('ends a session without authentication or a csrf token', function () {
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    enforceTerminalSecurityCsrf();
    auth()->logout();
    $this->postJson('/terminal/session/end', ['session_id' => $id, 'reason' => 'disconnected'])->assertNoContent();
    expect(fn () => app(TerminalSessionService::class)->check($this->user, $id))->toThrow(AccessDeniedHttpException::class);
});

it('rejects an unknown end reason without ending the session', function () {
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    enforceTerminalSecurityCsrf();
    $this->postJson('/terminal/session/end', ['session_id' => $id, 'reason' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    app(TerminalSessionService::class)->check($this->user, $id);
});

it('checks a session with the terminal servers csrf setup', function () {
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    enforceTerminalSecurityCsrf();
    $this->withSession(['_token' => 'terminal-csrf'])->postJson('/terminal/session/check', ['session_id' => $id], ['X-XSRF-TOKEN' => encrypt(CookieValuePrefix::create('XSRF-TOKEN', app('encrypter')->getKey()).'terminal-csrf', false)])->assertOk()->assertJson(['active' => true]);
});

it('rejects a session check without a csrf token', function () {
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    enforceTerminalSecurityCsrf();
    $this->postJson('/terminal/session/check', ['session_id' => $id])->assertStatus(419);
});

it('keeps a session after the user switches to a team that blocks pages', function (array $otherTeamAttributes) {
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    $otherTeam = Team::factory()->create($otherTeamAttributes);
    $this->user->teams()->attach($otherTeam, ['role' => 'owner']);
    session(['currentTeam' => $otherTeam]);

    $this->post('/terminal/session/check', ['session_id' => $id])->assertOk()->assertJson(['active' => true]);
})->with([
    'two-factor required' => [['is_two_factor_required' => true]],
    'onboarding' => [['show_boarding' => true]],
]);

it('rejects an active session after the server team requires two-factor authentication', function () {
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    $this->team->forceFill(['is_two_factor_required' => true])->saveQuietly();
    // The session team does not require it, so only the check on the server team can reject.
    $otherTeam = Team::factory()->create();
    $this->user->teams()->attach($otherTeam, ['role' => 'owner']);
    session(['currentTeam' => $otherTeam]);

    $this->postJson('/terminal/session/check', ['session_id' => $id])->assertForbidden();
});

it('rejects a session check after logout with an explicit status', function () {
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    auth()->logout();

    $this->post('/terminal/session/check', ['session_id' => $id], ['Accept' => 'application/json'])->assertUnauthorized();
});

it('rejects a token and an active session for a server transferred to another instance', function () {
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    $service = app(TerminalSessionService::class);
    $token = $service->issue($this->user, $server);
    $server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();
    Server::flushIdentityMap();

    expect(fn () => $service->check($this->user, $id))->toThrow(AccessDeniedHttpException::class)
        ->and(fn () => $service->redeem($this->user, $token))->toThrow(AccessDeniedHttpException::class);
});

function useCloudWithSubscription(Team $team, bool $paid = true): void
{
    config(['constants.coolify.self_hosted' => false, 'subscription.provider' => 'stripe']);
    Subscription::factory()->create(['team_id' => $team->id, 'stripe_invoice_paid' => $paid]);
}

it('rejects an active session and a new token on cloud after the server team subscription ends', function () {
    useCloudWithSubscription($this->team);
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    $service = app(TerminalSessionService::class);
    $token = $service->issue($this->user, $server);
    $this->team->subscription->update(['stripe_invoice_paid' => false]);
    // The session team still pays, so only the check on the server team can reject.
    $otherTeam = Team::factory()->create();
    useCloudWithSubscription($otherTeam);
    $this->user->teams()->attach($otherTeam, ['role' => 'owner']);
    session(['currentTeam' => $otherTeam]);

    $this->postJson('/terminal/session/check', ['session_id' => $id])->assertForbidden();

    session(['currentTeam' => $this->team]);
    expect(fn () => $service->redeem($this->user, $token))->toThrow(AccessDeniedHttpException::class);
});

it('keeps an active session on cloud while the server team subscription is active or ends at period end', function (array $subscription) {
    useCloudWithSubscription($this->team);
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);
    $this->team->subscription->update($subscription);
    // The session team has no subscription; the server team decides.
    $otherTeam = Team::factory()->create();
    $this->user->teams()->attach($otherTeam, ['role' => 'owner']);
    session(['currentTeam' => $otherTeam]);

    $this->postJson('/terminal/session/check', ['session_id' => $id])->assertOk()->assertJson(['active' => true]);
})->with([
    'paid' => [['stripe_invoice_paid' => true]],
    'cancel at period end' => [['stripe_invoice_paid' => false, 'stripe_cancel_at_period_end' => true]],
]);

it('keeps an active session for a self-hosted team without a subscription', function () {
    config(['constants.coolify.self_hosted' => true]);
    [$server, $id] = activeTerminalSecuritySession($this->user, $this->team);

    $this->postJson('/terminal/session/check', ['session_id' => $id])->assertOk()->assertJson(['active' => true]);
});

it('keeps an active session on cloud for a server of the root team without a subscription', function () {
    config(['constants.coolify.self_hosted' => false, 'subscription.provider' => 'stripe']);
    $rootTeam = Team::factory()->create(['id' => 0]);
    $this->user->teams()->attach($rootTeam, ['role' => 'owner']);
    session(['currentTeam' => $rootTeam]);
    [$server, $id] = activeTerminalSecuritySession($this->user, $rootTeam);

    app(TerminalSessionService::class)->check($this->user, $id);
})->throwsNoExceptions();
