<?php

use App\Models\InstanceSettings;
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
    config()->set('constants.sentinel.host_enabled', true);
    $keyPair = sodium_crypto_sign_seed_keypair(str_repeat('C', 32));
    config()->set('constants.flux.public_url', 'http://flux:7443');
    config()->set('constants.flux.development_allow_plaintext', true);
    config()->set('constants.flux.signing_key_id', 'dev-key');
    config()->set('constants.flux.signing_private_key', base64_encode(sodium_crypto_sign_secretkey($keyPair)));
    config()->set('constants.flux.signing_public_key', base64_encode(sodium_crypto_sign_publickey($keyPair)));
    config()->set('constants.flux.issuer', 'coolify-dev');

    $user = User::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $user->teams()->firstOrFail()->id,
        'mode' => 'node-worker',
    ]);
    $this->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $this->token = $this->server->settings->sentinel_token;
});

function requestSentinelAssignment(?string $token = null, array $payload = []): TestResponse
{
    $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

    return test()->postJson('/api/v1/sentinel/control/assignment', array_merge([
        'sentinel_version' => 'main',
        'protocol_min' => 1,
        'protocol_max' => 1,
        'capabilities' => ['system.ping.v1', 'system.info.v1'],
    ], $payload), $headers);
}

it('returns an enabled development assignment with a bound short-lived credential', function () {
    $assignment = requestSentinelAssignment($this->token)
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('server_id', $this->server->uuid)
        ->assertJsonPath('flux_url', 'http://flux:7443')
        ->assertJsonPath('trust_bundle_version', 1)
        ->json();

    $claims = (array) JWT::decode($assignment['credential'], new Key(config('constants.flux.signing_public_key'), 'EdDSA'));
    expect($claims)
        ->toMatchArray([
            'iss' => 'coolify-dev',
            'aud' => 'flux',
            'purpose' => 'v5-control-channel',
            'sub' => $this->server->uuid,
            'pmin' => 1,
            'pmax' => 1,
        ])
        ->and($claims['exp'] - $claims['iat'])->toBe(900)
        ->and($claims['caps'])->toBe(['system.ping.v1', 'system.info.v1']);
});

it('rejects a plaintext Flux endpoint without the development override', function () {
    config()->set('constants.flux.development_allow_plaintext', false);

    requestSentinelAssignment($this->token)->assertStatus(500);
});

it('requires the existing Sentinel token', function () {
    requestSentinelAssignment()->assertUnauthorized();
    requestSentinelAssignment('invalid-token')->assertUnauthorized();
});

it('allows a valid sentinel to reconnect while the server is marked unreachable', function () {
    $this->server->settings->update([
        'is_reachable' => false,
        'is_usable' => false,
    ]);

    requestSentinelAssignment($this->token)
        ->assertOk()
        ->assertJsonPath('server_id', $this->server->uuid);
});

it('derives the direct Flux URL from the Coolify URL and configured port', function () {
    config()->set('constants.flux.public_url', null);
    config()->set('constants.flux.port', 8443);
    config()->set('app.url', 'http://192.0.2.10:8000');

    requestSentinelAssignment($this->token)
        ->assertOk()
        ->assertJsonPath('flux_url', 'http://192.0.2.10:8443');
});

it('is unavailable when the development gate is disabled', function () {
    config()->set('constants.sentinel.host_enabled', false);

    requestSentinelAssignment($this->token)->assertNotFound();
});

it('is unavailable to legacy servers', function () {
    $this->server->update(['mode' => 'legacy']);

    requestSentinelAssignment($this->token)->assertNotFound();
});

it('is unavailable outside development even when the gate is enabled', function () {
    config()->set('app.env', 'production');

    requestSentinelAssignment($this->token)->assertNotFound();
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
