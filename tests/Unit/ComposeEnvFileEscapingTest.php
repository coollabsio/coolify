<?php

test('escapeComposeEnvFileValue wraps simple values in double quotes', function () {
    expect(escapeComposeEnvFileValue('runtime-only-value'))->toBe('"runtime-only-value"');
});

test('escapeComposeEnvFileValue keeps mixed quotes and dollars literal when interpolation is off', function () {
    $value = 'hello world; quotes" \'$`';

    $escaped = escapeComposeEnvFileValue($value, allowInterpolation: false);

    expect($escaped)->toBe('"hello world; quotes\\" \'\\$`"');
});

test('escapeComposeEnvFileValue blocks command substitution when interpolation is off', function () {
    $escaped = escapeComposeEnvFileValue('$(touch /tmp/should-not-exist)', allowInterpolation: false);

    expect($escaped)->toBe('"\\$(touch /tmp/should-not-exist)"');
});

test('escapeComposeEnvFileValue leaves $VAR intact when interpolation is on', function () {
    expect(escapeComposeEnvFileValue('$BOTH_PHASES', allowInterpolation: true))->toBe('"$BOTH_PHASES"');
});

test('escapeComposeEnvFileValue preserves multiline literals', function () {
    $escaped = escapeComposeEnvFileValue("line one\nline two", allowInterpolation: false);

    expect($escaped)->toBe("\"line one\nline two\"");
});

test('escapeComposeEnvFileValue escapes backslashes and double quotes', function () {
    expect(escapeComposeEnvFileValue('C:\\path\\"quoted"'))
        ->toBe('"C:\\\\path\\\\\\"quoted\\""');
});
