<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('constants.coolify.self_hosted', true);

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'allowed_ips' => '203.0.113.50,2001:db8::50']);

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->apiToken = $user->createToken('client-ip', ['read'], teamId: $team->id)->plainTextToken;
});

/**
 * An API call with a valid token: 403 when the allowed IPs block the client, 200 when they let it through.
 *
 * @param  array<string, string>  $headers
 */
function apiIpCheckFromPeer(string $peer, array $headers = []): TestResponse
{
    return test()
        ->withToken(test()->apiToken)
        ->withHeaders($headers)
        ->withServerVariables(['REMOTE_ADDR' => $peer])
        ->getJson('/api/v1/version');
}

it('ignores forwarded headers from a public peer for the allowed IPs', function (array $headers) {
    apiIpCheckFromPeer('198.51.100.7', $headers)->assertForbidden();
})->with([
    'X-Forwarded-For' => [['X-Forwarded-For' => '203.0.113.50']],
    'fake Cloudflare hop' => [['X-Forwarded-For' => '104.16.0.1', 'CF-Connecting-IP' => '203.0.113.50']],
]);

it('ignores forwarded headers from a public IPv6 peer for the allowed IPs', function (string $peer) {
    apiIpCheckFromPeer($peer, ['X-Forwarded-For' => '2001:db8::50'])->assertForbidden();
})->with(['2001:db8:ffff::7', '::ffff:198.51.100.7']);

it('uses the forwarded client from a local reverse proxy for the allowed IPs', function (string $peer, string $client) {
    apiIpCheckFromPeer($peer, ['X-Forwarded-For' => $client])->assertOk();
    apiIpCheckFromPeer($peer, ['X-Forwarded-For' => '198.51.100.7'])->assertForbidden();
})->with([
    'Docker network' => ['10.0.1.5', '203.0.113.50'],
    'Docker bridge' => ['172.18.0.2', '203.0.113.50'],
    'loopback' => ['127.0.0.1', '203.0.113.50'],
    'IPv6 loopback' => ['::1', '2001:db8::50'],
    'IPv6 unique local' => ['fd00::2', '2001:db8::50'],
    'IPv6 link local' => ['fe80::2', '2001:db8::50'],
    'IPv4-mapped private' => ['::ffff:10.0.1.5', '203.0.113.50'],
]);

it('uses the Cloudflare client IP behind a local reverse proxy only when the proxy received the request from Cloudflare', function () {
    apiIpCheckFromPeer('10.0.1.5', [
        'X-Forwarded-For' => '203.0.113.50, 162.158.79.140',
        'CF-Connecting-IP' => '203.0.113.50',
    ])->assertOk();

    apiIpCheckFromPeer('10.0.1.5', [
        'X-Forwarded-For' => '104.16.0.1, 198.51.100.7',
        'CF-Connecting-IP' => '203.0.113.50',
    ])->assertForbidden();
});

it('uses the Cloudflare client IP when Cloudflare connects directly', function () {
    apiIpCheckFromPeer('162.158.79.140', ['CF-Connecting-IP' => '203.0.113.50'])->assertOk();
});

it('keys the API rate limit by the public peer, not by forwarded headers', function () {
    config()->set('api.rate_limit', 2);
    InstanceSettings::query()->update(['allowed_ips' => null]);

    $push = fn (string $peer, array $headers) => test()
        ->withHeaders($headers)
        ->withServerVariables(['REMOTE_ADDR' => $peer])
        ->postJson('/api/v1/sentinel/push');

    $push('198.51.100.7', ['X-Forwarded-For' => '203.0.113.1'])->assertUnauthorized();
    $push('198.51.100.7', ['X-Forwarded-For' => '203.0.113.2', 'CF-Connecting-IP' => '203.0.113.2'])->assertUnauthorized();
    $push('198.51.100.7', ['X-Forwarded-For' => '104.16.0.1', 'CF-Connecting-IP' => '203.0.113.3'])->assertStatus(429);

    $push('10.0.1.5', ['X-Forwarded-For' => '203.0.113.10'])->assertUnauthorized();
    $push('10.0.1.5', ['X-Forwarded-For' => '203.0.113.20'])->assertUnauthorized();
});

it('ignores X-Forwarded-For from a public peer on cloud', function () {
    config()->set('constants.coolify.self_hosted', false);
    config()->set('api.rate_limit', 2);

    $push = fn (string $forwardedFor) => test()
        ->withHeader('X-Forwarded-For', $forwardedFor)
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson('/api/v1/sentinel/push');

    $push('203.0.113.1')->assertUnauthorized();
    $push('203.0.113.2')->assertUnauthorized();
    $push('203.0.113.3')->assertStatus(429);
});
