<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);

    $user = User::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $user->teams()->firstOrFail()->id,
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

it('returns a disabled development assignment for an authenticated Sentinel', function () {
    requestSentinelAssignment($this->token)
        ->assertOk()
        ->assertExactJson([
            'enabled' => false,
            'retry_after_seconds' => 10,
        ]);
});

it('requires the existing Sentinel token', function () {
    requestSentinelAssignment()->assertUnauthorized();
    requestSentinelAssignment('invalid-token')->assertUnauthorized();
});

it('is unavailable when the development gate is disabled', function () {
    config()->set('constants.sentinel.host_enabled', false);

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
