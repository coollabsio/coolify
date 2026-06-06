<?php

use App\Support\Networking\Cidr;

it('validates cidr notation', function (string $cidr, bool $expected) {
    expect(Cidr::isValid($cidr))->toBe($expected);
})->with([
    'valid /24' => ['172.30.10.0/24', true],
    'valid /16' => ['10.10.0.0/16', true],
    'valid /24 alt' => ['192.168.50.0/24', true],
    'missing mask' => ['172.30.10.0', false],
    'invalid octet' => ['999.1.1.0/24', false],
    'garbage' => ['abc', false],
    'bad prefix' => ['10.0.0.0/99', false],
]);

it('checks whether an ip belongs to a cidr block', function () {
    expect(Cidr::containsIp('172.30.10.0/24', '172.30.10.1'))->toBeTrue()
        ->and(Cidr::containsIp('172.30.10.0/24', '172.30.11.1'))->toBeFalse();
});

it('detects overlapping cidr blocks', function () {
    expect(Cidr::overlaps('172.30.10.0/24', '172.30.10.128/25'))->toBeTrue()
        ->and(Cidr::overlaps('172.30.10.0/24', '172.30.11.0/24'))->toBeFalse();
});
