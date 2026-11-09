<?php

use App\Helpers\SshMultiplexingHelper;
use App\Jobs\ServerConnectionCheckJob;
use App\Livewire\Server\Navbar;
use App\Livewire\Server\Security\HostKey;
use App\Livewire\Server\Security\Index;
use App\Livewire\Server\Security\Patches;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const PINNED_HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKgKlP9E2JBLjQ8DDxZUaI5vOJlvo8Uv06UAyqDbyC2I';
const PENDING_HOST_KEY_A = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHjaL3Lqt0tZl4iVZw8x1v2b9fM8r6Yq0nH0bUq3dQ0h';
const PENDING_HOST_KEY_B = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGq7Wb1c2d3e4f5g6h7i8j9k0l1m2n3o4p5q6r7s8t9u';

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    Queue::fake();
    $this->withoutVite();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'admin']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->forceFill([
        'ssh_host_key' => PINNED_HOST_KEY,
        'ssh_host_key_source' => 'tofu',
        'ssh_host_key_pending' => PENDING_HOST_KEY_A,
    ])->save();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('opens the host key page for an admin of the server team', function () {
    $this->get(route('server.security', ['server_uuid' => $this->server->uuid]))
        ->assertOk()
        ->assertSee('Trust new key');
});

it('trusts the pending key that the admin compared', function () {
    Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid])
        ->call('trustPendingKey', hash('sha256', PENDING_HOST_KEY_A), 'password')
        ->assertReturned(true);

    expect($this->server->settings->fresh())
        ->ssh_host_key->toBe(PENDING_HOST_KEY_A)
        ->ssh_host_key_pending->toBeNull();
});

it('does not trust a pending key that changed after the admin compared it', function () {
    $component = Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid]);
    $this->server->settings->forceFill(['ssh_host_key_pending' => PENDING_HOST_KEY_B])->save();

    $component->call('trustPendingKey', hash('sha256', PENDING_HOST_KEY_A), 'password');

    expect($this->server->settings->fresh())
        ->ssh_host_key->toBe(PINNED_HOST_KEY)
        ->ssh_host_key_pending->toBe(PENDING_HOST_KEY_B);
    Queue::assertNotPushed(ServerConnectionCheckJob::class);
});

it('sets the host key check to the state that the button showed, also when another tab changed it', function () {
    $component = Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid]);
    // Another tab disabled the check after this page loaded, so this page still shows "Disable check".
    $this->server->settings->forceFill(['is_ssh_host_key_check_disabled' => true])->save();

    $component->call('setHostKeyCheckDisabled', true, 'password')->assertReturned(true);

    expect($this->server->settings->fresh()->is_ssh_host_key_check_disabled)->toBeTrue();
    expect(AuditEvent::query()->where('event', 'ui.server.ssh_host_key.check_disabled')->exists())->toBeTrue();
    expect(AuditEvent::query()->where('event', 'ui.server.ssh_host_key.check_enabled')->exists())->toBeFalse();
});

it('enables the host key check again', function () {
    $this->server->settings->forceFill(['is_ssh_host_key_check_disabled' => true])->save();

    Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid])
        ->call('setHostKeyCheckDisabled', false, 'password')
        ->assertReturned(true);

    expect($this->server->settings->fresh()->is_ssh_host_key_check_disabled)->toBeFalse();
    expect(AuditEvent::query()->where('event', 'ui.server.ssh_host_key.check_enabled')->exists())->toBeTrue();
});

it('enforces the host key check on one server from the dropdown and stops enforcing it again', function () {
    config(['constants.ssh.host_key_checking' => 'warn']);

    Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid])
        ->call('setHostKeyCheckMode', 'enforce')
        ->assertReturned(true);

    expect($this->server->settings->fresh()->is_ssh_host_key_check_enforced)->toBeTrue();
    expect(SshMultiplexingHelper::hostKeyCheckingMode($this->server->fresh()))->toBe('enforce');
    expect(AuditEvent::query()->where('event', 'ui.server.ssh_host_key.check_enforced')->exists())->toBeTrue();

    Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid])
        ->call('setHostKeyCheckMode', 'warn')
        ->assertReturned(true);

    expect(SshMultiplexingHelper::hostKeyCheckingMode($this->server->fresh()))->toBe('warn');
});

it('does not turn a disabled host key check back on from a stale dropdown', function () {
    config(['constants.ssh.host_key_checking' => 'warn']);
    $component = Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid]);
    $this->server->settings->forceFill(['is_ssh_host_key_check_disabled' => true])->save();

    $component->call('setHostKeyCheckMode', 'enforce');

    expect($this->server->settings->fresh())
        ->is_ssh_host_key_check_disabled->toBeTrue()
        ->is_ssh_host_key_check_enforced->toBeFalse();
    expect(AuditEvent::query()->where('event', 'ui.server.ssh_host_key.check_enforced')->exists())->toBeFalse();
});

it('ignores a host key check mode that the dropdown does not offer', function () {
    Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid])
        ->call('setHostKeyCheckMode', 'off')
        ->assertReturned(false);

    expect($this->server->settings->fresh())
        ->is_ssh_host_key_check_disabled->toBeFalse()
        ->is_ssh_host_key_check_enforced->toBeFalse();
});

it('stops enforcing the host key check when an admin disables it', function () {
    $this->server->settings->forceFill(['is_ssh_host_key_check_enforced' => true])->save();

    Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid])
        ->call('setHostKeyCheckDisabled', true, 'password')
        ->assertReturned(true);

    expect($this->server->settings->fresh())
        ->is_ssh_host_key_check_disabled->toBeTrue()
        ->is_ssh_host_key_check_enforced->toBeFalse();
});

it('does not change the host key of a server that was transferred to another instance', function (string $action, array $arguments) {
    $component = Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid]);
    $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->save();

    $component->call($action, ...$arguments);

    expect($this->server->settings->fresh())
        ->ssh_host_key->toBe(PINNED_HOST_KEY)
        ->ssh_host_key_pending->toBe(PENDING_HOST_KEY_A)
        ->is_ssh_host_key_check_disabled->toBeFalse();
    Queue::assertNotPushed(ServerConnectionCheckJob::class);
})->with([
    'reset' => ['resetHostKey', ['password']],
    'trust' => ['trustPendingKey', [hash('sha256', PENDING_HOST_KEY_A), 'password']],
    'toggle' => ['setHostKeyCheckDisabled', [true, 'password']],
]);

it('shows the security tab for a server that a changed host key stopped', function () {
    $this->server->settings->forceFill(['is_reachable' => false, 'is_usable' => false])->save();

    Livewire::test(Navbar::class, ['server' => $this->server])
        ->assertSee(route('server.security', ['server_uuid' => $this->server->uuid]));
});

it('shows the patching page only when the server is functional', function () {
    $this->server->settings->forceFill(['is_reachable' => false, 'is_usable' => false])->save();
    Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Coolify cannot reach')
        ->assertDontSee('Check frequency');

    $this->server->settings->forceFill(['is_reachable' => true, 'is_usable' => true])->save();
    Livewire::test(Patches::class, ['server_uuid' => $this->server->fresh()->uuid])
        ->assertDontSee('Coolify cannot reach')
        ->assertSee('Check frequency');
});

it('queues the connection check instead of running it during the request', function () {
    Bus::fake([ServerConnectionCheckJob::class]);

    Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid])
        ->call('trustPendingKey', hash('sha256', PENDING_HOST_KEY_A), 'password')
        ->assertDispatched('hostKeySettingsChanged');

    Bus::assertNotDispatchedSync(ServerConnectionCheckJob::class);
    Bus::assertDispatched(ServerConnectionCheckJob::class, fn (ServerConnectionCheckJob $job) => $job->server->is($this->server));
});

it('polls until the queued connection check changes the server state', function () {
    $this->server->settings->forceFill(['is_reachable' => false, 'is_usable' => false])->save();
    $page = Livewire::test(Index::class, ['server_uuid' => $this->server->uuid])
        ->dispatch('hostKeySettingsChanged')
        ->assertSet('isWaitingForConnectionCheck', true)
        ->call('pollConnectionCheck')
        ->assertSet('isWaitingForConnectionCheck', true)
        ->assertDontSeeLivewire(Patches::class);

    $this->server->settings->forceFill(['is_reachable' => true, 'is_usable' => true])->save();

    // The page loads again, so that the patching section and the navbar show the new state.
    $page->call('pollConnectionCheck')
        ->assertRedirect(route('server.security', ['server_uuid' => $this->server->uuid]));
});

it('stops polling when the connection check takes longer than a minute', function () {
    $this->server->settings->forceFill(['is_reachable' => false, 'is_usable' => false])->save();
    $page = Livewire::test(Index::class, ['server_uuid' => $this->server->uuid])
        ->dispatch('hostKeySettingsChanged')
        ->assertSet('isWaitingForConnectionCheck', true);

    $this->travel(61)->seconds();

    $page->call('pollConnectionCheck')->assertSet('isWaitingForConnectionCheck', false);
});

/**
 * @return array<string, array{0: string, 1: array<int, mixed>}>
 */
function hostKeyPageActions(): array
{
    return [
        'reset' => ['resetHostKey', ['password']],
        'trust' => ['trustPendingKey', [hash('sha256', PENDING_HOST_KEY_A), 'password']],
        'disable check' => ['setHostKeyCheckDisabled', [true, 'password']],
        'enforce check' => ['setHostKeyCheckMode', ['enforce']],
    ];
}

function expectHostKeyUnchanged(Server $server): void
{
    expect($server->settings->fresh())
        ->ssh_host_key->toBe(PINNED_HOST_KEY)
        ->ssh_host_key_pending->toBe(PENDING_HOST_KEY_A)
        ->is_ssh_host_key_check_disabled->toBeFalse()
        ->is_ssh_host_key_check_enforced->toBeFalse();
    expect(AuditEvent::query()->where('event', 'like', 'ui.server.ssh_host_key.%')->exists())->toBeFalse();
    Queue::assertNotPushed(ServerConnectionCheckJob::class);
}

/**
 * The API request must authenticate with the token only, not with the user that the page tests log in.
 */
function hostKeyApiHeaders(string $token): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$token];
}

it('does not open the host key page for a member of the server team', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->user->refresh();

    $this->get(route('server.security', ['server_uuid' => $this->server->uuid]))
        ->assertForbidden()
        ->assertDontSee('Trust new key');

    Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid])
        ->assertRedirect(route('server.index'));
});

it('does not change the host key for a user who became a member after the page loaded', function (string $action, array $arguments) {
    $component = Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid]);
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->user->refresh();

    $component->call($action, ...$arguments)
        ->assertDispatched('error')
        ->assertNotDispatched('hostKeySettingsChanged');

    expectHostKeyUnchanged($this->server);
})->with(hostKeyPageActions());

it('does not open the host key page of a server of another team', function () {
    $outsider = User::factory()->create();
    $outsiderTeam = Team::factory()->create();
    $outsiderTeam->members()->attach($outsider->id, ['role' => 'owner']);
    $this->actingAs($outsider);
    session(['currentTeam' => $outsiderTeam]);

    $this->get(route('server.security', ['server_uuid' => $this->server->uuid]))
        ->assertForbidden()
        ->assertDontSee('Trust new key')
        ->assertDontSee($this->server->name);

    Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid])
        ->assertRedirect(route('server.index'));
    expectHostKeyUnchanged($this->server);
});

it('authorizes host key changes against the server team after the session switched to another team', function (string $action, array $arguments) {
    $ownTeam = Team::factory()->create();
    $ownTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $component = Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid]);
    session(['currentTeam' => $ownTeam]);

    $component->call($action, ...$arguments)->assertReturned(true);

    Queue::assertPushed(ServerConnectionCheckJob::class, fn (ServerConnectionCheckJob $job) => $job->server->is($this->server));
})->with(hostKeyPageActions());

it('denies host key changes to a member of the server team who owns the team that the session switched to', function (string $action, array $arguments) {
    $ownTeam = Team::factory()->create();
    $ownTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $component = Livewire::test(HostKey::class, ['server_uuid' => $this->server->uuid]);
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->user->refresh();
    session(['currentTeam' => $ownTeam]);

    $component->call($action, ...$arguments)
        ->assertDispatched('error')
        ->assertNotDispatched('hostKeySettingsChanged');

    expectHostKeyUnchanged($this->server);
})->with(hostKeyPageActions());

it('resets the host key through the API with a write token', function () {
    $token = $this->user->createToken('host-key-write', ['write'])->plainTextToken;

    $this->withHeaders(hostKeyApiHeaders($token))
        ->postJson("/api/v1/servers/{$this->server->uuid}/host-key/reset")
        ->assertOk()
        ->assertJson(['message' => 'Host key reset.']);

    expect($this->server->settings->fresh())
        ->ssh_host_key->toBeNull()
        ->ssh_host_key_pending->toBeNull();
    Queue::assertPushed(ServerConnectionCheckJob::class);
});

it('does not reset the host key through the API with a read-only token', function () {
    $token = $this->user->createToken('host-key-read', ['read'])->plainTextToken;

    $this->withHeaders(hostKeyApiHeaders($token))
        ->postJson("/api/v1/servers/{$this->server->uuid}/host-key/reset")
        ->assertForbidden();

    expectHostKeyUnchanged($this->server);
});

it('does not reset the host key through the API with a write token of a member', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->user->refresh();
    $token = $this->user->createToken('host-key-member', ['write'])->plainTextToken;

    $this->withHeaders(hostKeyApiHeaders($token))
        ->postJson("/api/v1/servers/{$this->server->uuid}/host-key/reset")
        ->assertForbidden();

    expectHostKeyUnchanged($this->server);
});

it('does not reset the host key of a server of another team through the API', function () {
    $outsider = User::factory()->create();
    $outsiderTeam = Team::factory()->create();
    $outsiderTeam->members()->attach($outsider->id, ['role' => 'owner']);
    $token = $outsider->createToken('host-key-other-team', ['write'], teamId: $outsiderTeam->id)->plainTextToken;

    $this->withHeaders(hostKeyApiHeaders($token))
        ->postJson("/api/v1/servers/{$this->server->uuid}/host-key/reset")
        ->assertNotFound()
        ->assertJsonMissingPath('ssh_host_key_fingerprints');

    expectHostKeyUnchanged($this->server);
});
