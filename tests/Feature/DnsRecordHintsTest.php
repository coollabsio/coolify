<?php

use App\Support\DnsRecordHints;

it('builds a and aaaa records for a single hostname', function () {
    $records = DnsRecordHints::forTarget('app.example.com', '203.0.113.10', '2001:db8::1');

    expect($records)->toHaveCount(2)
        ->and($records[0])->toMatchArray([
            'type' => 'A',
            'name' => 'app.example.com',
            'value' => '203.0.113.10',
        ])
        ->and($records[1])->toMatchArray([
            'type' => 'AAAA',
            'name' => 'app.example.com',
            'value' => '2001:db8::1',
        ]);
});

it('builds entries for every hostname without duplicates', function () {
    $records = DnsRecordHints::forHostnames([
        'app.example.com',
        'www.example.com',
        'app.example.com',
        'https://api.example.com/path',
    ], '203.0.113.10');

    expect($records)->toHaveCount(3)
        ->and(collect($records)->pluck('name')->all())->toBe([
            'api.example.com',
            'app.example.com',
            'www.example.com',
        ])
        ->and(collect($records)->pluck('type')->unique()->all())->toBe(['A']);
});

it('formats a BIND-compatible zone snippet for copy all', function () {
    $text = DnsRecordHints::toCopyText([
        ['type' => 'A', 'name' => 'app.example.com', 'value' => '203.0.113.10'],
        ['type' => 'A', 'name' => 'www.example.com', 'value' => '203.0.113.10'],
        ['type' => 'AAAA', 'name' => 'app.example.com', 'value' => '2001:db8::1'],
    ]);

    expect($text)->toBe(
        "app.example.com.  IN A     203.0.113.10\n".
        "www.example.com.  IN A     203.0.113.10\n".
        "app.example.com.  IN AAAA  2001:db8::1\n"
    );
});

it('treats only publicly routable addresses as public dns record targets', function (string $address, bool $public) {
    expect(DnsRecordHints::isPublicAddress($address))->toBe($public);
})->with([
    'public ipv4' => ['8.8.8.8', true],
    'public ipv6' => ['2606:4700::1111', true],
    'rfc1918 10/8' => ['10.0.0.5', false],
    'rfc1918 172.16/12' => ['172.20.1.5', false],
    'rfc1918 192.168/16' => ['192.168.1.5', false],
    'cgnat start' => ['100.64.0.1', false],
    'tailscale cgnat' => ['100.100.100.100', false],
    'cgnat end' => ['100.127.255.254', false],
    'just outside cgnat' => ['100.128.0.1', true],
    'loopback' => ['127.0.0.1', false],
    'link-local' => ['169.254.10.1', false],
    'unspecified' => ['0.0.0.0', false],
    'multicast' => ['224.0.0.1', false],
    'ipv6 unique local' => ['fd00::5', false],
    'ipv6 link-local' => ['fe80::5', false],
    'ipv6 loopback' => ['::1', false],
    'ipv6 multicast' => ['ff02::1', false],
    'ipv4-mapped private' => ['::ffff:10.0.0.5', false],
    'ipv4-mapped cgnat' => ['::ffff:100.64.0.1', false],
    'not an ip' => ['app.example.com', false],
    'empty' => ['', false],
]);

it('compares IP addresses independent of their notation', function (string $first, string $second, bool $same) {
    expect(DnsRecordHints::sameAddress($first, $second))->toBe($same);
})->with([
    'compressed vs expanded IPv6' => ['2001:db8::1', '2001:0db8:0:0::1', true],
    'upper vs lower case IPv6' => ['2001:DB8::A', '2001:db8::a', true],
    'fully expanded IPv6' => ['2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1', true],
    'different IPv6' => ['2001:db8::1', '2001:db8::2', false],
    'same IPv4' => ['203.0.113.10', '203.0.113.10', true],
    'different IPv4' => ['203.0.113.10', '203.0.113.11', false],
    'IPv4 vs IPv6' => ['203.0.113.10', '::ffff:203.0.113.10', false],
    'non-IP values' => ['target.example.com', 'target.example.com', true],
]);
