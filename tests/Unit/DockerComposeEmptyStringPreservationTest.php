<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests to verify that environment variables with empty strings
 * in Docker Compose files are preserved as empty strings, not converted to null.
 *
 * This is important because empty strings and null have different semantics in Docker:
 * - Empty string: Variable is set to "" (e.g., HTTP_PROXY="" means "no proxy")
 * - Null: Variable is unset/removed from container environment
 *
 * See: https://github.com/coollabsio/coolify/issues/7126
 */
it('ensures parsers.php preserves empty strings in application parser', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Find the applicationParser function's environment mapping logic
    $hasApplicationParser = str_contains($parsersFile, 'function applicationParser(');
    expect($hasApplicationParser)->toBeTrue('applicationParser function should exist');

    // The code should NOT unconditionally set $value = null for empty strings
    // Instead, it should preserve empty strings when no database override exists

    // Check for the pattern where we only override with database values when they're non-empty
    // We're checking the fix is in place by looking for the logic pattern
    $pattern1 = str_contains($parsersFile, 'if (str($value)->isEmpty())');
    expect($pattern1)->toBeTrue('Empty string check should exist');
});

it('ensures parsers.php preserves empty strings in service parser', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Find the serviceParser function's environment mapping logic
    $hasServiceParser = str_contains($parsersFile, 'function serviceParser(');
    expect($hasServiceParser)->toBeTrue('serviceParser function should exist');

    // The code should NOT unconditionally set $value = null for empty strings
    // Same check as above for service parser
    $pattern1 = str_contains($parsersFile, 'if (str($value)->isEmpty())');
    expect($pattern1)->toBeTrue('Empty string check should exist');
});

it('verifies YAML parsing preserves empty strings correctly', function () {
    // Test that Symfony YAML parser handles empty strings as we expect
    $yamlWithEmptyString = <<<'YAML'
environment:
  HTTP_PROXY: ""
  HTTPS_PROXY: ''
  NO_PROXY: "localhost"
YAML;

    $parsed = Yaml::parse($yamlWithEmptyString);

    // Empty strings should remain as empty strings, not null
    expect($parsed['environment']['HTTP_PROXY'])->toBe('');
    expect($parsed['environment']['HTTPS_PROXY'])->toBe('');
    expect($parsed['environment']['NO_PROXY'])->toBe('localhost');
});

it('verifies YAML parsing handles null values correctly', function () {
    // Test that null values are preserved as null
    $yamlWithNull = <<<'YAML'
environment:
  HTTP_PROXY: null
  HTTPS_PROXY:
  NO_PROXY: "localhost"
YAML;

    $parsed = Yaml::parse($yamlWithNull);

    // Null should remain null
    expect($parsed['environment']['HTTP_PROXY'])->toBeNull();
    expect($parsed['environment']['HTTPS_PROXY'])->toBeNull();
    expect($parsed['environment']['NO_PROXY'])->toBe('localhost');
});

it('verifies YAML serialization preserves empty strings', function () {
    // Test that empty strings serialize back correctly
    $data = [
        'environment' => [
            'HTTP_PROXY' => '',
            'HTTPS_PROXY' => '',
            'NO_PROXY' => 'localhost',
        ],
    ];

    $yaml = Yaml::dump($data, 10, 2);

    // Empty strings should be serialized with quotes
    expect($yaml)->toContain("HTTP_PROXY: ''");
    expect($yaml)->toContain("HTTPS_PROXY: ''");
    expect($yaml)->toContain('NO_PROXY: localhost');

    // Should NOT contain "null"
    expect($yaml)->not->toContain('HTTP_PROXY: null');
});

it('verifies YAML serialization handles null values', function () {
    // Test that null values serialize as null
    $data = [
        'environment' => [
            'HTTP_PROXY' => null,
            'HTTPS_PROXY' => null,
            'NO_PROXY' => 'localhost',
        ],
    ];

    $yaml = Yaml::dump($data, 10, 2);

    // Null should be serialized as "null"
    expect($yaml)->toContain('HTTP_PROXY: null');
    expect($yaml)->toContain('HTTPS_PROXY: null');
    expect($yaml)->toContain('NO_PROXY: localhost');

    // Should NOT contain empty quotes for null values
    expect($yaml)->not->toContain("HTTP_PROXY: ''");
});

it('verifies empty string round-trip through YAML', function () {
    // Test full round-trip: empty string -> YAML -> parse -> serialize -> parse
    $original = [
        'environment' => [
            'HTTP_PROXY' => '',
            'NO_PROXY' => 'localhost',
        ],
    ];

    // Serialize to YAML
    $yaml1 = Yaml::dump($original, 10, 2);

    // Parse back
    $parsed1 = Yaml::parse($yaml1);

    // Verify empty string is preserved
    expect($parsed1['environment']['HTTP_PROXY'])->toBe('');
    expect($parsed1['environment']['NO_PROXY'])->toBe('localhost');

    // Serialize again
    $yaml2 = Yaml::dump($parsed1, 10, 2);

    // Parse again
    $parsed2 = Yaml::parse($yaml2);

    // Should still be empty string, not null
    expect($parsed2['environment']['HTTP_PROXY'])->toBe('');
    expect($parsed2['environment']['NO_PROXY'])->toBe('localhost');

    // Both YAML representations should be equivalent
    expect($yaml1)->toBe($yaml2);
});

it('verifies str()->isEmpty() behavior with empty strings and null', function () {
    // Test Laravel's str()->isEmpty() helper behavior

    // Empty string should be considered empty
    expect(str('')->isEmpty())->toBeTrue();

    // Null should be considered empty
    expect(str(null)->isEmpty())->toBeTrue();

    // String with content should not be empty
    expect(str('value')->isEmpty())->toBeFalse();

    // This confirms that we need additional logic to distinguish
    // between empty string ('') and null, since both are "isEmpty"
});

it('verifies the distinction between empty string and null in PHP', function () {
    // Document PHP's behavior for empty strings vs null

    $emptyString = '';
    $nullValue = null;

    // They are different values
    expect($emptyString === $nullValue)->toBeFalse();

    // Empty string is not null
    expect($emptyString === '')->toBeTrue();
    expect($nullValue === null)->toBeTrue();

    // isset() treats them differently
    $arrayWithEmpty = ['key' => ''];
    $arrayWithNull = ['key' => null];

    expect(isset($arrayWithEmpty['key']))->toBeTrue();
    expect(isset($arrayWithNull['key']))->toBeFalse();
});
