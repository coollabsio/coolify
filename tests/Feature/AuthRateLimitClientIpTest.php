<?php

use Illuminate\Http\Request;

it('uses the Cloudflare client ip on cloud', function () {
    config()->set('constants.coolify.self_hosted', false);

    $request = Request::create('/', server: [
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_CF_CONNECTING_IP' => '2001:db8::10',
    ]);

    expect(client_ip($request))->toBe('2001:db8::10');
});

it('falls back to the resolved request ip when the Cloudflare header is invalid', function () {
    config()->set('constants.coolify.self_hosted', false);

    $request = Request::create('/', server: [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_CF_CONNECTING_IP' => 'invalid',
    ]);

    expect(client_ip($request))->toBe('203.0.113.10');
});

it('uses the Cloudflare client ip on self-hosted instances when the request comes from Cloudflare', function (string $cloudflareEdge) {
    config()->set('constants.coolify.self_hosted', true);

    $request = Request::create('/', server: [
        'REMOTE_ADDR' => $cloudflareEdge,
        'HTTP_CF_CONNECTING_IP' => '2001:db8::20',
    ]);

    expect(client_ip($request))->toBe('2001:db8::20');
})->with(['162.158.79.140', '104.24.0.1', '2a06:98c0::1']);

it('ignores the Cloudflare header on self-hosted instances when the request does not come from Cloudflare', function () {
    config()->set('constants.coolify.self_hosted', true);

    $request = Request::create('/', server: [
        'REMOTE_ADDR' => '203.0.113.20',
        'HTTP_CF_CONNECTING_IP' => '2001:db8::20',
    ]);

    expect(client_ip($request))->toBe('203.0.113.20');
});
