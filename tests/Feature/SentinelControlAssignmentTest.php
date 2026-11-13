<?php

use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    setNodesEnabled(true);
    $keyPair = sodium_crypto_sign_seed_keypair(str_repeat('C', 32));
    config()->set('constants.flux.public_url', 'http://flux:7443');
    config()->set('constants.flux.development_allow_plaintext', true);
    config()->set('constants.flux.signing_key_id', 'dev-key');
    config()->set('constants.flux.signing_private_key', base64_encode(sodium_crypto_sign_secretkey($keyPair)));
    config()->set('constants.flux.signing_public_key', base64_encode(sodium_crypto_sign_publickey($keyPair)));
    config()->set('constants.flux.issuer', 'coolify-dev');

    $user = User::factory()->create();
    $this->node = Node::factory()->create([
        'team_id' => $user->teams()->firstOrFail()->id,
    ]);
    $this->token = $this->node->ensureValidSentinelToken();
});

function requestSentinelAssignment(?string $token = null, array $payload = []): TestResponse
{
    $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

    return test()->postJson('/api/v1/sentinel/control/assignment', array_merge([
        'sentinel_version' => 'main',
        'protocol_min' => 1,
        'protocol_max' => 1,
        'capabilities' => ['system.ping.v1', 'system.info.v1', 'container.list.v1', 'workload.deploy.v1', 'workload.lifecycle.v1'],
    ], $payload), $headers);
}

it('returns an enabled development assignment with a bound short-lived credential', function () {
    $assignment = requestSentinelAssignment($this->token)
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('server_id', $this->node->uuid)
        ->assertJsonPath('flux_url', 'http://flux:7443')
        ->assertJsonPath('trust_bundle_version', 1)
        ->json();

    $claims = (array) JWT::decode($assignment['credential'], new Key(config('constants.flux.signing_public_key'), 'EdDSA'));
    expect($claims)
        ->toMatchArray([
            'iss' => 'coolify-dev',
            'aud' => 'flux',
            'purpose' => 'node-control-channel',
            'sub' => $this->node->uuid,
            'pmin' => 1,
            'pmax' => 1,
        ])
        ->and($claims['exp'] - $claims['iat'])->toBe(900)
        ->and($claims['caps'])->toBe(['system.ping.v1', 'system.info.v1', 'container.list.v1', 'workload.deploy.v1', 'workload.lifecycle.v1']);
});

it('rotates the short-lived Flux credential on every assignment', function () {
    $first = requestSentinelAssignment($this->token)->assertOk()->json('credential');
    $second = requestSentinelAssignment($this->token)->assertOk()->json('credential');

    $firstClaims = (array) JWT::decode($first, new Key(config('constants.flux.signing_public_key'), 'EdDSA'));
    $secondClaims = (array) JWT::decode($second, new Key(config('constants.flux.signing_public_key'), 'EdDSA'));

    expect($second)->not->toBe($first)
        ->and($secondClaims['jti'])->not->toBe($firstClaims['jti'])
        ->and($secondClaims['sub'])->toBe($this->node->uuid)
        ->and($secondClaims['exp'] - $secondClaims['iat'])->toBe(900);
});

it('no longer grants the removed endpoint reconciliation capability', function () {
    $assignment = requestSentinelAssignment($this->token, [
        'capabilities' => ['system.ping.v1', 'discovery.corrosion.endpoints.reconcile.v1'],
    ])
        ->assertOk()
        ->json();

    $claims = (array) JWT::decode($assignment['credential'], new Key(config('constants.flux.signing_public_key'), 'EdDSA'));
    expect($claims['caps'])->toBe(['system.ping.v1']);
});

it('grants the log read capability to Nodes that advertise it', function () {
    $assignment = requestSentinelAssignment($this->token, [
        'capabilities' => ['system.ping.v1', 'logs.read.v1', 'logs.follow.v1'],
    ])
        ->assertOk()
        ->json();

    $claims = (array) JWT::decode($assignment['credential'], new Key(config('constants.flux.signing_public_key'), 'EdDSA'));
    expect($claims['caps'])->toBe(['system.ping.v1', 'logs.read.v1']);
});

it('grants the container log capability to Nodes that advertise it', function () {
    $assignment = requestSentinelAssignment($this->token, [
        'capabilities' => ['system.ping.v1', 'container.logs.v1'],
    ])
        ->assertOk()
        ->json();

    $claims = (array) JWT::decode($assignment['credential'], new Key(config('constants.flux.signing_public_key'), 'EdDSA'));
    expect($claims['caps'])->toBe(['system.ping.v1', 'container.logs.v1']);
});

it('rejects a plaintext Flux endpoint without the development override', function () {
    config()->set('constants.flux.development_allow_plaintext', false);

    requestSentinelAssignment($this->token)->assertStatus(500);
});

it('requires the existing Sentinel token', function () {
    requestSentinelAssignment()->assertUnauthorized();
    requestSentinelAssignment('invalid-token')->assertUnauthorized();
});

it('allows a valid sentinel to reconnect while the node is marked unreachable', function () {
    $this->node->update([
        'is_reachable' => false,
        'is_usable' => false,
    ]);

    requestSentinelAssignment($this->token)
        ->assertOk()
        ->assertJsonPath('server_id', $this->node->uuid);
});

it('derives the Flux URL from the instance domain and the Flux port', function () {
    config()->set('constants.flux.public_url', null);
    config()->set('constants.flux.port', 8443);
    InstanceSettings::query()->whereKey(0)->update(['fqdn' => 'https://coolify.example.com', 'public_ipv4' => '192.0.2.10']);

    requestSentinelAssignment($this->token)
        ->assertOk()
        ->assertJsonPath('flux_url', 'https://coolify.example.com:8443');
});

it('uses the Flux URL setting over the instance domain', function () {
    config()->set('constants.flux.public_url', null);
    InstanceSettings::query()->whereKey(0)->update(['fqdn' => 'https://coolify.example.com', 'flux_host' => '2001:db8::10', 'flux_port' => 9443]);

    requestSentinelAssignment($this->token)
        ->assertOk()
        ->assertJsonPath('flux_url', 'https://[2001:db8::10]:9443');
});

it('fails without a host for the Flux URL', function () {
    config()->set('constants.flux.public_url', null);

    $this->withoutExceptionHandling();

    expect(fn () => requestSentinelAssignment($this->token))
        ->toThrow(RuntimeException::class, 'Flux URL configuration is invalid. Set the Flux URL in Settings > Advanced.');
});

it('keeps serving existing Nodes when the Nodes setting is off', function () {
    setNodesEnabled(false);

    requestSentinelAssignment($this->token)
        ->assertOk()
        ->assertJsonPath('server_id', $this->node->uuid);
});

it('is unavailable when the Nodes setting is off and no Node exists', function () {
    setNodesEnabled(false);
    $this->node->delete();

    requestSentinelAssignment($this->token)->assertNotFound();
});

it('does not accept a legacy server token for node assignment', function () {
    $server = Server::factory()->create(['team_id' => $this->node->team_id]);

    requestSentinelAssignment($server->settings->sentinel_token)->assertUnauthorized();
});

it('serves assignments in production over TLS', function () {
    config()->set('app.env', 'production');
    config()->set('constants.flux.public_url', 'https://flux.example.com:7443');

    requestSentinelAssignment($this->token)
        ->assertOk()
        ->assertJsonPath('flux_url', 'https://flux.example.com:7443');
});

it('validates the Sentinel protocol request', function (array $payload, string $field) {
    requestSentinelAssignment($this->token, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing version' => [['sentinel_version' => null], 'sentinel_version'],
    'invalid protocol minimum' => [['protocol_min' => 0], 'protocol_min'],
    'inverted protocol range' => [['protocol_min' => 2, 'protocol_max' => 1], 'protocol_max'],
    'missing capabilities' => [['capabilities' => null], 'capabilities'],
    'invalid capability' => [['capabilities' => ['']], 'capabilities.0'],
]);

it('rejects an incompatible protocol range', function () {
    requestSentinelAssignment($this->token, [
        'protocol_min' => 2,
        'protocol_max' => 3,
    ])->assertConflict();
});
