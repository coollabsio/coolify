<?php

test('escapeBashEnvValue wraps simple values in single quotes', function () {
    $result = escapeBashEnvValue('simple_value');
    expect($result)->toBe("'simple_value'");
});

test('escapeBashEnvValue handles special bash characters', function () {
    $specialChars = [
        '$&#)@*~$&@(~#&#%(*$324803129&$#@!)*&$',
        '#*#&412)$&#*!%)!@&#)*~@!&$)@*#%^)*@#!)#@~321',
        'value with spaces and $variables',
        'value with `backticks`',
        'value with "double quotes"',
        'value|with|pipes',
        'value;with;semicolons',
        'value&with&ampersands',
        'value(with)parentheses',
        'value{with}braces',
        'value[with]brackets',
        'value<with>angles',
        'value*with*asterisks',
        'value?with?questions',
        'value!with!exclamations',
        'value~with~tildes',
        'value^with^carets',
        'value%with%percents',
        'value@with@ats',
        'value#with#hashes',
    ];

    foreach ($specialChars as $value) {
        $result = escapeBashEnvValue($value);

        // Should be wrapped in single quotes
        expect($result)->toStartWith("'");
        expect($result)->toEndWith("'");

        // Should contain the original value (or escaped version)
        expect($result)->toContain($value);
    }
});

test('escapeBashEnvValue escapes single quotes correctly', function () {
    // Single quotes in bash single-quoted strings must be escaped as '\''
    $value = "it's a value with 'single quotes'";
    $result = escapeBashEnvValue($value);

    // The result should replace ' with '\''
    expect($result)->toBe("'it'\\''s a value with '\\''single quotes'\\'''");
});

test('escapeBashEnvValue handles empty values', function () {
    $result = escapeBashEnvValue('');
    expect($result)->toBe("''");
});

test('escapeBashEnvValue handles null values', function () {
    $result = escapeBashEnvValue(null);
    expect($result)->toBe("''");
});

test('escapeBashEnvValue handles values with only special characters', function () {
    $value = '$#@!*&^%()[]{}|;~`?"<>';
    $result = escapeBashEnvValue($value);

    // Should be wrapped and contain all special characters
    expect($result)->toBe("'{$value}'");
});

test('escapeBashEnvValue handles multiline values', function () {
    $value = "line1\nline2\nline3";
    $result = escapeBashEnvValue($value);

    // Should preserve newlines
    expect($result)->toContain("\n");
    expect($result)->toStartWith("'");
    expect($result)->toEndWith("'");
});

test('escapeBashEnvValue handles values from user example', function () {
    $literal = '$&#)@*~$&@(~#&#%(*$324803129&$#@!)*&$';
    $weird = '#*#&412)$&#*!%)!@&#)*~@!&$)@*#%^)*@#!)#@~321';

    $escapedLiteral = escapeBashEnvValue($literal);
    $escapedWeird = escapeBashEnvValue($weird);

    // These should be safely wrapped in single quotes
    expect($escapedLiteral)->toBe("'{$literal}'");
    expect($escapedWeird)->toBe("'{$weird}'");

    // Test that when written to a file and sourced, they would work
    // Format: KEY=VALUE
    $envLine1 = "literal={$escapedLiteral}";
    $envLine2 = "weird={$escapedWeird}";

    // These should be valid bash assignment statements
    expect($envLine1)->toStartWith('literal=');
    expect($envLine2)->toStartWith('weird=');
});

test('escapeBashEnvValue handles backslashes', function () {
    $value = 'path\\to\\file';
    $result = escapeBashEnvValue($value);

    // Backslashes should be preserved in single quotes
    expect($result)->toBe("'{$value}'");
    expect($result)->toContain('\\');
});

test('escapeBashEnvValue handles dollar signs correctly', function () {
    $value = '$HOME and $PATH';
    $result = escapeBashEnvValue($value);

    // Dollar signs should NOT be expanded in single quotes
    expect($result)->toBe("'{$value}'");
    expect($result)->toContain('$HOME');
    expect($result)->toContain('$PATH');
});

test('escapeBashEnvValue handles complex combination of special characters and single quotes', function () {
    $value = "it's \$weird with 'quotes' and \$variables";
    $result = escapeBashEnvValue($value);

    // Should escape the single quotes
    expect($result)->toContain("'\\''");
    // Should contain the dollar signs without expansion
    expect($result)->toContain('$weird');
    expect($result)->toContain('$variables');
});

test('stripping quotes from real_value before escaping (literal/multiline simulation)', function () {
    // Simulate what happens with literal/multiline env vars
    // Their real_value comes back wrapped in quotes: 'value'
    $realValueWithQuotes = "'it's a value with 'quotes''";

    // Strip outer quotes
    $stripped = trim($realValueWithQuotes, "'");
    expect($stripped)->toBe("it's a value with 'quotes");

    // Then apply bash escaping
    $result = escapeBashEnvValue($stripped);

    // Should properly escape the internal single quotes
    expect($result)->toContain("'\\''");
    // Should start and end with quotes
    expect($result)->toStartWith("'");
    expect($result)->toEndWith("'");
});

test('handling literal env with special bash characters', function () {
    // Simulate literal/multiline env with special characters
    $realValueWithQuotes = "'#*#&412)\$&#*!%)!@&#)*~@!\&\$)@*#%^)*@#!)#@~321'";

    // Strip outer quotes
    $stripped = trim($realValueWithQuotes, "'");

    // Apply bash escaping
    $result = escapeBashEnvValue($stripped);

    // Should be properly quoted for bash
    expect($result)->toStartWith("'");
    expect($result)->toEndWith("'");
    // Should contain all the special characters
    expect($result)->toContain('#*#&412)');
    expect($result)->toContain('$&#*!%');
});

// ==================== Tests for escapeBashDoubleQuoted() ====================

test('escapeBashDoubleQuoted wraps simple values in double quotes', function () {
    $result = escapeBashDoubleQuoted('simple_value');
    expect($result)->toBe('"simple_value"');
});

test('escapeBashDoubleQuoted handles null values', function () {
    $result = escapeBashDoubleQuoted(null);
    expect($result)->toBe('""');
});

test('escapeBashDoubleQuoted handles empty values', function () {
    $result = escapeBashDoubleQuoted('');
    expect($result)->toBe('""');
});

test('escapeBashDoubleQuoted preserves valid variable references', function () {
    $value = '$SOURCE_COMMIT';
    $result = escapeBashDoubleQuoted($value);

    // Should preserve $SOURCE_COMMIT for expansion
    expect($result)->toBe('"$SOURCE_COMMIT"');
    expect($result)->toContain('$SOURCE_COMMIT');
});

test('escapeBashDoubleQuoted preserves multiple variable references', function () {
    $value = '$VAR1 and $VAR2 and $VAR_NAME_3';
    $result = escapeBashDoubleQuoted($value);

    // All valid variables should be preserved
    expect($result)->toBe('"$VAR1 and $VAR2 and $VAR_NAME_3"');
});

test('escapeBashDoubleQuoted preserves brace expansion variables', function () {
    $value = '${SOURCE_COMMIT} and ${VAR_NAME}';
    $result = escapeBashDoubleQuoted($value);

    // Brace variables should be preserved
    expect($result)->toBe('"${SOURCE_COMMIT} and ${VAR_NAME}"');
});

test('escapeBashDoubleQuoted escapes invalid dollar patterns', function () {
    // Invalid patterns: $&, $#, $$, $*, $@, $!, etc.
    $value = '$&#)@*~$&@(~#&#%(*$324803129&$#@!)*&$';
    $result = escapeBashDoubleQuoted($value);

    // Invalid $ should be escaped
    expect($result)->toContain('\\$&#');
    expect($result)->toContain('\\$&@');
    expect($result)->toContain('\\$#@');
    // Should be wrapped in double quotes
    expect($result)->toStartWith('"');
    expect($result)->toEndWith('"');
});

test('escapeBashDoubleQuoted handles mixed valid and invalid dollar signs', function () {
    $value = '$SOURCE_COMMIT and $&#invalid';
    $result = escapeBashDoubleQuoted($value);

    // Valid variable preserved, invalid $ escaped
    expect($result)->toBe('"$SOURCE_COMMIT and \\$&#invalid"');
});

test('escapeBashDoubleQuoted escapes double quotes', function () {
    $value = 'value with "double quotes"';
    $result = escapeBashDoubleQuoted($value);

    // Double quotes should be escaped
    expect($result)->toBe('"value with \\"double quotes\\""');
});

test('escapeBashDoubleQuoted escapes backticks', function () {
    $value = 'value with `backticks`';
    $result = escapeBashDoubleQuoted($value);

    // Backticks should be escaped (prevents command substitution)
    expect($result)->toBe('"value with \\`backticks\\`"');
});

test('escapeBashDoubleQuoted escapes backslashes', function () {
    $value = 'path\\to\\file';
    $result = escapeBashDoubleQuoted($value);

    // Backslashes should be escaped
    expect($result)->toBe('"path\\\\to\\\\file"');
});

test('escapeBashDoubleQuoted handles positional parameters', function () {
    $value = 'args: $0 $1 $2 $9';
    $result = escapeBashDoubleQuoted($value);

    // Positional parameters should be preserved
    expect($result)->toBe('"args: $0 $1 $2 $9"');
});

test('escapeBashDoubleQuoted handles special variable $_', function () {
    $value = 'last arg: $_';
    $result = escapeBashDoubleQuoted($value);

    // $_ should be preserved
    expect($result)->toBe('"last arg: $_"');
});

test('escapeBashDoubleQuoted handles complex real-world scenario', function () {
    // Mix of valid vars, invalid $, quotes, and special chars
    $value = '$SOURCE_COMMIT with $&#special and "quotes" and `cmd`';
    $result = escapeBashDoubleQuoted($value);

    // Valid var preserved, invalid $ escaped, quotes/backticks escaped
    expect($result)->toBe('"$SOURCE_COMMIT with \\$&#special and \\"quotes\\" and \\`cmd\\`"');
});

test('escapeBashDoubleQuoted allows expansion in bash', function () {
    // This is a logical test - the actual expansion happens in bash
    // We're verifying the format is correct
    $value = '$SOURCE_COMMIT';
    $result = escapeBashDoubleQuoted($value);

    // Should be: "$SOURCE_COMMIT" which bash will expand
    expect($result)->toBe('"$SOURCE_COMMIT"');
    expect($result)->not->toContain('\\$SOURCE');
});

test('comparison between single and double quote escaping', function () {
    $value = '$SOURCE_COMMIT';

    $singleQuoted = escapeBashEnvValue($value);
    $doubleQuoted = escapeBashDoubleQuoted($value);

    // Single quotes prevent expansion
    expect($singleQuoted)->toBe("'\$SOURCE_COMMIT'");

    // Double quotes allow expansion
    expect($doubleQuoted)->toBe('"$SOURCE_COMMIT"');

    // They're different!
    expect($singleQuoted)->not->toBe($doubleQuoted);
});
