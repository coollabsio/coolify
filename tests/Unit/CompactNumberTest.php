<?php

it('formats counts with K, M, and B suffixes', function (?int $input, string $expected) {
    expect(compactNumber($input))->toBe($expected);
})->with([
    'null' => [null, '0'],
    'below a thousand' => [999, '999'],
    'round thousand' => [1000, '1K'],
    'thousands' => [26_360, '26.36K'],
    'millions' => [1_200_000, '1.2M'],
    'billions' => [3_456_789_012, '3.46B'],
]);
