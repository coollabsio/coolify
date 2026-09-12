<?php

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.flux.internal_token', 'internal-secret');
    config()->set('constants.flux.public_url', 'http://flux:7443');
    config()->set('constants.flux.development_allow_plaintext', true);
    $user = User::factory()->create();
    $this->node = Node::factory()->create([
        'team_id' => $user->teams()->firstOrFail()->id,
    ]);
});

it('records a bounded Flux connection observation', function () {
    $this->postJson('/api/v1/internal/sentinel/control/events', [
        'event' => 'connected',
        'server_id' => $this->node->uuid,
        'connection_id' => '11111111-1111-4111-8111-111111111111',
        'sentinel_version' => 'main',
        'protocol_version' => 1,
        'trust_bundle_version' => 1,
        'transport' => 'plaintext',
    ], ['Authorization' => 'Bearer internal-secret'])->assertNoContent();

    expect(Cache::get("flux:connection:{$this->node->uuid}"))->toMatchArray([
        'status' => 'connected',
        'connection_id' => '11111111-1111-4111-8111-111111111111',
        'protocol_version' => 1,
        'trust_bundle_version' => 1,
        'transport' => 'plaintext',
        'endpoint' => 'http://flux:7443',
    ]);
});

it('uses the derived TLS endpoint in connection observations', function () {
    config()->set('constants.flux.public_url', null);
    config()->set('constants.flux.port', 7443);
    config()->set('app.url', 'https://coolify.example.com');

    $this->postJson('/api/v1/internal/sentinel/control/events', [
        'event' => 'connected',
        'server_id' => $this->node->uuid,
        'connection_id' => '11111111-1111-4111-8111-111111111111',
        'sentinel_version' => 'main',
        'protocol_version' => 1,
        'trust_bundle_version' => 1,
        'transport' => 'tls',
    ], ['Authorization' => 'Bearer internal-secret'])->assertNoContent();

    expect(Cache::get("flux:connection:{$this->node->uuid}"))->toMatchArray([
        'transport' => 'tls',
        'endpoint' => 'https://coolify.example.com:7443',
    ]);
});

it('keeps connection state stable while Sentinel refreshes its credential', function () {
    Carbon::setTestNow('2026-09-12 11:13:55');
    $connectedPayload = [
        'event' => 'connected',
        'server_id' => $this->node->uuid,
        'connection_id' => '11111111-1111-4111-8111-111111111111',
        'sentinel_version' => 'main',
        'protocol_version' => 1,
        'trust_bundle_version' => 1,
        'transport' => 'tls',
    ];

    $this->postJson('/api/v1/internal/sentinel/control/events', $connectedPayload, ['Authorization' => 'Bearer internal-secret'])
        ->assertNoContent();

    foreach (['11:17:00', '11:21:00', '11:25:00', '11:27:54'] as $heartbeatTime) {
        Carbon::setTestNow("2026-09-12 {$heartbeatTime}");
        $this->postJson('/api/v1/internal/sentinel/control/events', [
            'event' => 'heartbeat',
            'server_id' => $this->node->uuid,
            'connection_id' => $connectedPayload['connection_id'],
        ], ['Authorization' => 'Bearer internal-secret'])->assertNoContent();
    }

    Carbon::setTestNow('2026-09-12 11:27:55');
    $this->postJson('/api/v1/internal/sentinel/control/events', [
        'event' => 'disconnected',
        'server_id' => $this->node->uuid,
        'connection_id' => $connectedPayload['connection_id'],
    ], ['Authorization' => 'Bearer internal-secret'])->assertNoContent();

    expect(Cache::get("flux:connection:{$this->node->uuid}"))->toMatchArray([
        'status' => 'reconnecting',
        'connected_at' => '2026-09-12T11:13:55+00:00',
    ]);

    Carbon::setTestNow('2026-09-12 11:27:56');
    $this->postJson('/api/v1/internal/sentinel/control/events', [
        ...$connectedPayload,
        'connection_id' => '22222222-2222-4222-8222-222222222222',
    ], ['Authorization' => 'Bearer internal-secret'])->assertNoContent();

    expect(Cache::get("flux:connection:{$this->node->uuid}"))->toMatchArray([
        'status' => 'connected',
        'connection_id' => '22222222-2222-4222-8222-222222222222',
        'connected_at' => '2026-09-12T11:13:55+00:00',
    ]);
});

it('rejects invalid internal credentials and unknown servers', function () {
    $payload = ['event' => 'connected', 'server_id' => $this->node->uuid, 'connection_id' => '11111111-1111-4111-8111-111111111111', 'sentinel_version' => 'main', 'protocol_version' => 1, 'trust_bundle_version' => 1, 'transport' => 'tls'];
    $this->postJson('/api/v1/internal/sentinel/control/events', $payload)->assertUnauthorized();
    $this->postJson('/api/v1/internal/sentinel/control/events', [...$payload, 'server_id' => 'missing'], ['Authorization' => 'Bearer internal-secret'])->assertNotFound();
});

it('rejects connection events for legacy servers', function () {
    $server = Server::factory()->create(['team_id' => $this->node->team_id]);

    $this->postJson('/api/v1/internal/sentinel/control/events', [
        'event' => 'connected',
        'server_id' => $server->uuid,
        'connection_id' => '11111111-1111-4111-8111-111111111111',
        'sentinel_version' => 'main',
        'protocol_version' => 1,
        'trust_bundle_version' => 1,
        'transport' => 'tls',
    ], ['Authorization' => 'Bearer internal-secret'])->assertNotFound();
});
