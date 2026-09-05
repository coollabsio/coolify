<?php

use App\Support\DomainUrlParts;

it('composes a domain URL from segmented fields', function () {
    expect(DomainUrlParts::compose('https', 'app.example.com', '3000', 'api/v3'))
        ->toBe('https://app.example.com:3000/api/v3');
});

it('splits a domain URL while preserving its path query and fragment', function () {
    expect(DomainUrlParts::split('http://app.example.com:8080/api?v=1#docs'))->toBe([
        'scheme' => 'http',
        'host' => 'app.example.com',
        'port' => '8080',
        'path' => '/api?v=1#docs',
    ]);
});

it('defaults empty values for an invalid URL', function () {
    expect(DomainUrlParts::split(''))->toBe([
        'scheme' => 'https',
        'host' => '',
        'port' => '',
        'path' => '',
    ]);
});

it('preserves an explicitly configured default port', function () {
    expect(DomainUrlParts::split('https://app.example.com:443')['port'])->toBe('443');
});

it('composes a domain when Livewire hydrates an empty numeric port as null', function () {
    expect(DomainUrlParts::compose('https', 'app.example.com', null))
        ->toBe('https://app.example.com');
});
