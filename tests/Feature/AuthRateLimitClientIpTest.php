<?php

use Illuminate\Http\Request;

it('uses the Cloudflare client ip on cloud', function () {
    config()->set('constants.coolify.self_hosted', false);

    $request = Request::create('/', server: [
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_CF_CONNECTING_IP' => '2001:db8::10',
    ]);

    expect(auth_rate_limit_ip($request))->toBe('2001:db8::10');
});

it('falls back to the resolved request ip when the Cloudflare header is invalid', function () {
    config()->set('constants.coolify.self_hosted', false);

    $request = Request::create('/', server: [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_CF_CONNECTING_IP' => 'invalid',
    ]);

    expect(auth_rate_limit_ip($request))->toBe('203.0.113.10');
});

it('ignores the Cloudflare header on self-hosted instances', function () {
    config()->set('constants.coolify.self_hosted', true);

    $request = Request::create('/', server: [
        'REMOTE_ADDR' => '203.0.113.20',
        'HTTP_CF_CONNECTING_IP' => '2001:db8::20',
    ]);

    expect(auth_rate_limit_ip($request))->toBe('203.0.113.20');
});
