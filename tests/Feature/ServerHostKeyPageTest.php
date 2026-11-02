<?php

use App\Jobs\ServerConnectionCheckJob;
use App\Livewire\Server\Security\HostKey;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const PINNED_HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKgKlP9E2JBLjQ8DDxZUaI5vOJlvo8Uv06UAyqDbyC2I';
const PENDING_HOST_KEY_A = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHjaL3Lqt0tZl4iVZw8x1v2b9fM8r6Yq0nH0bUq3dQ0h';
const PENDING_HOST_KEY_B = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGq7Wb1c2d3e4f5g6h7i8j9k0l1m2n3o4p5q6r7s8t9u';

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
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
    $this->get(route('server.security.host-key', ['server_uuid' => $this->server->uuid]))
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
    'toggle' => ['toggleHostKeyCheck', ['password']],
]);
