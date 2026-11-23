<?php

use App\Actions\Proxy\StopProxy;
use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Listeners\ProxyStatusChangedNotification;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

// Servers stay in a static identity map; do not leak them into later test files.
afterEach(fn () => Server::flushIdentityMap());

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);
    Process::fake();

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->server->markProxyTransitionStarted();
});

test('the listener updates the proxy of the server of the event team', function () {
    Event::fake([ProxyStatusChangedUI::class]);

    (new ProxyStatusChangedNotification)->handle(new ProxyStatusChanged(ProxyStatusChanged::dataFor($this->server)));

    expect($this->server->fresh()->isProxyTransitionInProgress())->toBeFalse();
    Process::assertRan(fn () => true);
});

test('the listener ignores a server that is not in the event team', function () {
    $otherTeam = Team::factory()->create();

    (new ProxyStatusChangedNotification)->handle(new ProxyStatusChanged([
        'server_id' => $this->server->id,
        'team_id' => $otherTeam->id,
    ]));

    expect($this->server->fresh()->isProxyTransitionInProgress())->toBeTrue();
    Process::assertNothingRan();
});

test('the listener ignores event data without a team', function (mixed $data) {
    (new ProxyStatusChangedNotification)->handle(new ProxyStatusChanged($data));

    expect($this->server->fresh()->isProxyTransitionInProgress())->toBeTrue();
    Process::assertNothingRan();
})->with([
    'bare server id' => fn () => $this->server->id,
    'no team id' => fn () => ['server_id' => $this->server->id],
]);

test('stopping the proxy dispatches the event with the server and its team', function () {
    Event::fake([ProxyStatusChanged::class, ProxyStatusChangedUI::class]);

    StopProxy::run($this->server);

    Event::assertDispatched(ProxyStatusChanged::class, fn (ProxyStatusChanged $event) => $event->data === [
        'server_id' => $this->server->id,
        'team_id' => $this->team->id,
    ]);
});
