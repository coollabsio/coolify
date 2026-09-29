<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('api.rate_limit', 2);

    InstanceSettings::forceCreate(['id' => 0]);
});

function pushSentinelFrom(string $clientIp): TestResponse
{
    return test()
        ->withHeader('CF-Connecting-IP', $clientIp)
        ->withServerVariables(['REMOTE_ADDR' => '172.70.0.1'])
        ->postJson('/api/v1/sentinel/push');
}

it('gives each Cloudflare client ip its own sentinel push bucket on cloud', function () {
    config()->set('constants.coolify.self_hosted', false);

    pushSentinelFrom('203.0.113.10')->assertUnauthorized();
    pushSentinelFrom('203.0.113.10')->assertUnauthorized();
    pushSentinelFrom('203.0.113.10')->assertStatus(429);

    pushSentinelFrom('203.0.113.20')->assertUnauthorized();
});

it('ignores the Cloudflare client ip for sentinel pushes on self-hosted instances', function () {
    config()->set('constants.coolify.self_hosted', true);

    pushSentinelFrom('203.0.113.10')->assertUnauthorized();
    pushSentinelFrom('203.0.113.10')->assertUnauthorized();

    pushSentinelFrom('203.0.113.20')->assertStatus(429);
});

it('keys unauthenticated limiters by the Cloudflare client ip on cloud', function (string $limiter) {
    config()->set('constants.coolify.self_hosted', false);

    $request = Request::create('/', server: [
        'REMOTE_ADDR' => '172.70.0.1',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
    ]);

    $limit = RateLimiter::limiter($limiter)($request);

    expect($limit->key)->toBe('203.0.113.10');
})->with(['api', '5', 'feedback']);
