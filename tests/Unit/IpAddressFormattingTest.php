<?php

it('normalizes IP addresses to one stored form', function (string $input, string $expected) {
    expect(normalizeIpAddress($input))->toBe($expected);
})->with([
    'IPv4' => ['188.245.12.182', '188.245.12.182'],
    'IPv4 with spaces' => [' 188.245.12.182 ', '188.245.12.182'],
    'hostname' => ['server.example.com', 'server.example.com'],
    'IPv6 short form' => ['2a01:4f8:c016:bd23::1', '2a01:4f8:c016:bd23::1'],
    'IPv6 upper case with zeros' => ['2A01:04F8:C016:BD23:0000:0000:0000:0001', '2a01:4f8:c016:bd23::1'],
    'IPv6 in brackets' => ['[2a01:4f8:c016:bd23::1]', '2a01:4f8:c016:bd23::1'],
    'not an IP in brackets' => ['[not-an-ip]', '[not-an-ip]'],
]);

it('adds brackets only to IPv6 hosts for URLs', function (string $input, string $expected) {
    expect(formatHostForUrl($input))->toBe($expected)
        ->and(formatHostForUrl(formatHostForUrl($input)))->toBe($expected);
})->with([
    'IPv4' => ['188.245.12.182', '188.245.12.182'],
    'hostname' => ['coolify.example.com', 'coolify.example.com'],
    'IPv6' => ['2a01:4f8:c016:bd23::1', '[2a01:4f8:c016:bd23::1]'],
    'IPv6 already in brackets' => ['[2a01:4f8:c016:bd23::1]', '[2a01:4f8:c016:bd23::1]'],
    'IPv6 long form' => ['2a01:04f8:c016:bd23:0:0:0:1', '[2a01:4f8:c016:bd23::1]'],
]);

it('turns the Hetzner IPv6 network into the server address', function (?string $network, ?string $expected) {
    expect(hetznerServerIpv6($network))->toBe($expected);
})->with([
    'network' => ['2a01:4f8:c016:bd23::/64', '2a01:4f8:c016:bd23::1'],
    'address without prefix length' => ['2a01:4f8:c016:bd23::1', '2a01:4f8:c016:bd23::1'],
    'empty' => [null, null],
    'not IPv6' => ['188.245.12.182', null],
]);

it('builds sslip host labels that are valid for every IPv6 address', function (string $ip, string $expected) {
    expect(sslipHostLabel($ip))->toBe($expected);
})->with([
    'IPv4' => ['188.245.12.182', '188.245.12.182'],
    'IPv6' => ['2a01:4f8:c016:bd23::1', '2a01-4f8-c016-bd23--1'],
    'IPv6 that ends with ::' => ['2a01:4f8:c016:bd23::', '2a01-4f8-c016-bd23--0'],
    'IPv6 that starts with ::' => ['::1', '0--1'],
]);
