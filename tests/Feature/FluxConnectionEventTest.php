<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.flux.internal_token', 'internal-secret');
    config()->set('constants.flux.public_url', 'http://flux:7443');
    config()->set('constants.flux.development_allow_plaintext', true);
    $user = User::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $user->teams()->firstOrFail()->id]);
});

it('records a bounded Flux connection observation', function () {
    $this->postJson('/api/v1/internal/sentinel/control/events', [
        'event' => 'connected',
        'server_id' => $this->server->uuid,
        'connection_id' => '11111111-1111-4111-8111-111111111111',
        'sentinel_version' => 'main',
        'protocol_version' => 1,
        'trust_bundle_version' => 1,
        'transport' => 'plaintext',
    ], ['Authorization' => 'Bearer internal-secret'])->assertNoContent();

    expect(Cache::get("flux:connection:{$this->server->uuid}"))->toMatchArray([
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
        'server_id' => $this->server->uuid,
        'connection_id' => '11111111-1111-4111-8111-111111111111',
        'sentinel_version' => 'main',
        'protocol_version' => 1,
        'trust_bundle_version' => 1,
        'transport' => 'tls',
    ], ['Authorization' => 'Bearer internal-secret'])->assertNoContent();

    expect(Cache::get("flux:connection:{$this->server->uuid}"))->toMatchArray([
        'transport' => 'tls',
        'endpoint' => 'https://coolify.example.com:7443',
    ]);
});

it('rejects invalid internal credentials and unknown servers', function () {
    $payload = ['event' => 'connected', 'server_id' => $this->server->uuid, 'connection_id' => '11111111-1111-4111-8111-111111111111', 'sentinel_version' => 'main', 'protocol_version' => 1, 'trust_bundle_version' => 1, 'transport' => 'tls'];
    $this->postJson('/api/v1/internal/sentinel/control/events', $payload)->assertUnauthorized();
    $this->postJson('/api/v1/internal/sentinel/control/events', [...$payload, 'server_id' => 'missing'], ['Authorization' => 'Bearer internal-secret'])->assertNotFound();
});
