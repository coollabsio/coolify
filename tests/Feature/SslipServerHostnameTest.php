<?php

use App\Models\Server;

it('builds the sslip.io domain from the IP address of the server', function (string $address, string $expected) {
    $server = new Server(['ip' => $address]);
    $server->id = 1;

    expect(sslip($server))->toBe($expected);
})->with([
    'IPv4' => ['188.245.12.182', 'http://188.245.12.182.sslip.io'],
    'IPv6' => ['2a01:4f8:c016:bd23::1', 'http://2a01-4f8-c016-bd23--1.sslip.io'],
    'resolvable hostname' => ['localhost', 'http://127.0.0.1.sslip.io'],
    'unresolvable hostname' => ['coolify-test.invalid', 'http://coolify-test.invalid.sslip.io'],
]);
