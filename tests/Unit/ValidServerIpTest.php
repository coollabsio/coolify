<?php

use App\Rules\ValidServerIp;
use Tests\TestCase;

uses(TestCase::class);

it('accepts private and reserved IP addresses like the next branch', function (string $ip) {
    $rule = new ValidServerIp;
    $failCalled = false;

    $rule->validate('ip', $ip, function () use (&$failCalled): void {
        $failCalled = true;
    });

    expect($failCalled)->toBeFalse();
})->with([
    'private IPv4' => '192.168.1.10',
    'loopback IPv4' => '127.0.0.1',
    'link-local IPv4' => '169.254.1.10',
    'loopback IPv6' => '::1',
    'unique local IPv6' => 'fd00::1',
]);
