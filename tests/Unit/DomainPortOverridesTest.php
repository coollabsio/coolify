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
