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

it('accepts an IPv6 address in brackets', function (string $ip) {
    $failCalled = false;

    (new ValidServerIp)->validate('ip', $ip, function () use (&$failCalled): void {
        $failCalled = true;
    });

    expect($failCalled)->toBeFalse();
})->with([
    'short form' => '[2a01:4f8::1]',
    'long form' => '[2A01:04F8:0000:0000:0000:0000:0000:0001]',
]);

it('rejects brackets around anything other than an IPv6 address', function (string $ip) {
    $failCalled = false;

    (new ValidServerIp)->validate('ip', $ip, function () use (&$failCalled): void {
        $failCalled = true;
    });

    expect($failCalled)->toBeTrue();
})->with([
    'IPv4' => '[192.168.1.10]',
    'hostname' => '[server.example.com]',
    'missing closing bracket' => '[2a01:4f8::1',
]);
