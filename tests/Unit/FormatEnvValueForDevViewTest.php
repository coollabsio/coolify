<?php

it('formats values so that parseEnvFormatToArray reads them back unchanged', function (string $value) {
    $formatted = formatEnvValueForDevView($value);

    expect(parseEnvFormatToArray("KEY={$formatted}"))
        ->toBe(['KEY' => ['value' => $value, 'comment' => null]]);
})->with([
    'plain' => ['plain-value'],
    'empty' => [''],
    'double quoted' => ['"abc"'],
    'single quoted' => ["'\$FOO'"],
    'leading double quote only' => ['"abc'],
    'quotes inside' => ['He said "hi" and \'bye\''],
    'hash after whitespace' => ['value #not-a-comment'],
    'hash without whitespace' => ['value#hash'],
    'double quoted with suffix' => ['"Company :: App" <noreply@example.com>'],
]);

it('returns null for values that the env format cannot hold', function (string $value) {
    expect(formatEnvValueForDevView($value))->toBeNull();
})->with([
    'both quote types and a leading quote' => ['\'"Company :: App" <noreply@example.com>\''],
    'line break' => ["first\nsecond"],
]);
