<?php

use App\Support\DomainFavicon;

it('builds the favicon URL for a public domain', function (string $url, string $expected) {
    expect(DomainFavicon::url($url))->toBe($expected);
})->with([
    'domain' => ['https://app.example.com', 'https://app.example.com/favicon.ico'],
    'domain with port' => ['https://app.example.com:8443', 'https://app.example.com:8443/favicon.ico'],
    'public IP' => ['http://8.8.8.8', 'http://8.8.8.8/favicon.ico'],
    'public sslip.io' => ['http://app.1.2.3.4.sslip.io', 'http://app.1.2.3.4.sslip.io/favicon.ico'],
]);

it('skips the favicon for a private network host', function (string $url) {
    expect(DomainFavicon::url($url))->toBeNull();
})->with([
    'private IPv4' => ['http://192.168.1.10'],
    'unique local IPv6' => ['http://[fd00::1]'],
    'tailscale CGNAT' => ['http://100.101.102.103'],
    'loopback' => ['http://127.0.0.1:8000'],
    'localhost' => ['http://localhost'],
    'mDNS name' => ['http://nas.local'],
    'internal name' => ['http://app.internal'],
    'private sslip.io' => ['http://app.10.0.0.5.sslip.io'],
    'private nip.io with dashes' => ['http://10-0-0-5.nip.io'],
    'invalid URL' => ['not a url'],
]);

it('skips the favicon when the DNS check matched a private server IP', function () {
    expect(DomainFavicon::url('https://app.example.com', 'ok', '10.0.0.5'))->toBeNull();
});

it('keeps the favicon when the DNS check did not confirm a private server IP', function (?string $status, ?string $expectedIp) {
    expect(DomainFavicon::url('https://app.example.com', $status, $expectedIp))
        ->toBe('https://app.example.com/favicon.ico');
})->with([
    'mismatch' => ['failed', '10.0.0.5'],
    'public server IP' => ['ok', '8.8.8.8'],
    'no server IP' => ['ok', null],
]);
