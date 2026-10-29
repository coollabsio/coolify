<?php

it('formats latency in ms, s, or min', function (float|int|null $input, string $expected) {
    expect(formatLatency($input))->toBe($expected);
})->with([
    'null' => [null, '0.0 ms'],
    'milliseconds' => [85.44, '85.4 ms'],
    'just below a second' => [999.9, '999.9 ms'],
    'seconds' => [2_450, '2.5 s'],
    'minutes' => [312_000, '5.2 min'],
]);
