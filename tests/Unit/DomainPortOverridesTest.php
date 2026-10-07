<?php

use App\Support\DomainPortOverrides;

it('copies the source port override to an automatically paired domain', function (string $source, string $counterpart) {
    $result = DomainPortOverrides::normalize(
        "$source,$counterpart",
        [$source => 8080],
    );

    expect($result['overrides'])->toBe([
        $source => 8080,
        $counterpart => 8080,
    ]);
})->with([
    'www redirect' => ['https://example.com', 'https://www.example.com'],
    'non-www redirect' => ['https://www.example.com', 'https://example.com'],
]);

it('keeps an explicit override on the paired domain', function (string $source, string $counterpart) {
    $result = DomainPortOverrides::normalize(
        "$source,$counterpart",
        [
            $source => 8080,
            $counterpart => 9090,
        ],
    );

    expect($result['overrides'])->toBe([
        $source => 8080,
        $counterpart => 9090,
    ]);
})->with([
    'www redirect' => ['https://example.com', 'https://www.example.com'],
    'non-www redirect' => ['https://www.example.com', 'https://example.com'],
]);

it('drops hostless domains and their port overrides', function () {
    $result = DomainPortOverrides::normalize(
        'https://,https://example.com',
        [
            'https://' => 3000,
            'https://example.com' => 8080,
        ],
    );

    expect($result)->toBe([
        'fqdn' => 'https://example.com',
        'overrides' => ['https://example.com' => 8080],
    ]);
});

it('clears an fqdn that contains only a hostless domain', function () {
    expect(DomainPortOverrides::normalize('https://', ['https://' => 3000]))->toBe([
        'fqdn' => null,
        'overrides' => null,
    ]);
});
