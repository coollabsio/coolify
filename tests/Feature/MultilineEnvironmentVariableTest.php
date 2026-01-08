<?php

test('multiline environment variables are properly escaped for docker build args', function () {
    $sshKey = '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----';

    $variables = [
        ['key' => 'SSH_PRIVATE_KEY', 'value' => "'{$sshKey}'", 'is_multiline' => true],
        ['key' => 'REGULAR_VAR', 'value' => 'simple value', 'is_multiline' => false],
    ];

    $buildArgs = generateDockerBuildArgs($variables);

    // SSH key should use double quotes and have proper escaping
    $sshArg = $buildArgs->first();
    expect($sshArg)->toStartWith('--build-arg SSH_PRIVATE_KEY="');
    expect($sshArg)->toEndWith('"');
    expect($sshArg)->toContain('BEGIN OPENSSH PRIVATE KEY');
    expect($sshArg)->not->toContain("'BEGIN"); // Should not have the wrapper single quotes

    // Regular var should use escapeshellarg (single quotes)
    $regularArg = $buildArgs->last();
    expect($regularArg)->toBe("--build-arg REGULAR_VAR='simple value'");
});

test('multiline variables with special bash characters are escaped correctly', function () {
    $valueWithSpecialChars = "line1\nline2 with \"quotes\"\nline3 with \$variables\nline4 with `backticks`";

    $variables = [
        ['key' => 'SPECIAL_VALUE', 'value' => "'{$valueWithSpecialChars}'", 'is_multiline' => true],
    ];

    $buildArgs = generateDockerBuildArgs($variables);
    $arg = $buildArgs->first();

    // Verify double quotes are escaped
    expect($arg)->toContain('\\"quotes\\"');
    // Verify dollar signs are escaped
    expect($arg)->toContain('\\$variables');
    // Verify backticks are escaped
    expect($arg)->toContain('\\`backticks\\`');
});

test('single-line environment variables use escapeshellarg', function () {
    $variables = [
        ['key' => 'SIMPLE_VAR', 'value' => 'simple value with spaces', 'is_multiline' => false],
    ];

    $buildArgs = generateDockerBuildArgs($variables);
    $arg = $buildArgs->first();

    // Should use single quotes from escapeshellarg
    expect($arg)->toBe("--build-arg SIMPLE_VAR='simple value with spaces'");
});

test('multiline certificate with newlines is preserved', function () {
    $certificate = '-----BEGIN CERTIFICATE-----
MIIDXTCCAkWgAwIBAgIJAKL0UG+mRkSvMA0GCSqGSIb3DQEBCwUAMEUxCzAJBgNV
BAYTAkFVMRMwEQYDVQQIDApTb21lLVN0YXRlMSEwHwYDVQQKDBhJbnRlcm5ldCBX
aWRnaXRzIFB0eSBMdGQwHhcNMTkwOTE3MDUzMzI5WhcNMjkwOTE0MDUzMzI5WjBF
-----END CERTIFICATE-----';

    $variables = [
        ['key' => 'TLS_CERT', 'value' => "'{$certificate}'", 'is_multiline' => true],
    ];

    $buildArgs = generateDockerBuildArgs($variables);
    $arg = $buildArgs->first();

    // Newlines should be preserved in the output
    expect($arg)->toContain("\n");
    expect($arg)->toContain('BEGIN CERTIFICATE');
    expect($arg)->toContain('END CERTIFICATE');
    expect(substr_count($arg, "\n"))->toBeGreaterThan(0);
});

test('multiline JSON configuration is properly escaped', function () {
    $jsonConfig = '{
  "key": "value",
  "nested": {
    "array": [1, 2, 3]
  }
}';

    $variables = [
        ['key' => 'JSON_CONFIG', 'value' => "'{$jsonConfig}'", 'is_multiline' => true],
    ];

    $buildArgs = generateDockerBuildArgs($variables);
    $arg = $buildArgs->first();

    // All double quotes in JSON should be escaped
    expect($arg)->toContain('\\"key\\"');
    expect($arg)->toContain('\\"value\\"');
    expect($arg)->toContain('\\"nested\\"');
});

test('empty multiline variable is handled correctly', function () {
    $variables = [
        ['key' => 'EMPTY_VAR', 'value' => "''", 'is_multiline' => true],
    ];

    $buildArgs = generateDockerBuildArgs($variables);
    $arg = $buildArgs->first();

    expect($arg)->toBe('--build-arg EMPTY_VAR=""');
});

test('multiline variable with only newlines', function () {
    $onlyNewlines = "\n\n\n";

    $variables = [
        ['key' => 'NEWLINES_ONLY', 'value' => "'{$onlyNewlines}'", 'is_multiline' => true],
    ];

    $buildArgs = generateDockerBuildArgs($variables);
    $arg = $buildArgs->first();

    expect($arg)->toContain("\n");
    // Should have 3 newlines preserved
    expect(substr_count($arg, "\n"))->toBe(3);
});

test('multiline variable with backslashes is escaped correctly', function () {
    $valueWithBackslashes = "path\\to\\file\nC:\\Windows\\System32";

    $variables = [
        ['key' => 'PATH_VAR', 'value' => "'{$valueWithBackslashes}'", 'is_multiline' => true],
    ];

    $buildArgs = generateDockerBuildArgs($variables);
    $arg = $buildArgs->first();

    // Backslashes should be doubled
    expect($arg)->toContain('path\\\\to\\\\file');
    expect($arg)->toContain('C:\\\\Windows\\\\System32');
});

test('generateDockerEnvFlags produces correct format', function () {
    $variables = [
        ['key' => 'NORMAL_VAR', 'value' => 'value', 'is_multiline' => false],
        ['key' => 'MULTILINE_VAR', 'value' => "'line1\nline2'", 'is_multiline' => true],
    ];

    $envFlags = generateDockerEnvFlags($variables);

    expect($envFlags)->toContain('-e NORMAL_VAR=');
    expect($envFlags)->toContain('-e MULTILINE_VAR="');
    expect($envFlags)->toContain('line1');
    expect($envFlags)->toContain('line2');
});

test('helper functions work with collection input', function () {
    $variables = collect([
        (object) ['key' => 'VAR1', 'value' => 'value1', 'is_multiline' => false],
        (object) ['key' => 'VAR2', 'value' => "'multiline\nvalue'", 'is_multiline' => true],
    ]);

    $buildArgs = generateDockerBuildArgs($variables);
    expect($buildArgs)->toHaveCount(2);

    $envFlags = generateDockerEnvFlags($variables);
    expect($envFlags)->toBeString();
    expect($envFlags)->toContain('-e VAR1=');
    expect($envFlags)->toContain('-e VAR2="');
});

test('variables without is_multiline default to false', function () {
    $variables = [
        ['key' => 'NO_FLAG_VAR', 'value' => 'some value'],
    ];

    $buildArgs = generateDockerBuildArgs($variables);
    $arg = $buildArgs->first();

    // Should use escapeshellarg (single quotes) since is_multiline defaults to false
    expect($arg)->toBe("--build-arg NO_FLAG_VAR='some value'");
});

test('real world SSH key example', function () {
    // Simulate what real_value returns (wrapped in single quotes)
    $sshKey = "'-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----'";

    $variables = [
        ['key' => 'KEY', 'value' => $sshKey, 'is_multiline' => true],
    ];

    $buildArgs = generateDockerBuildArgs($variables);
    $arg = $buildArgs->first();

    // Should produce clean output without wrapper quotes
    expect($arg)->toStartWith('--build-arg KEY="-----BEGIN OPENSSH PRIVATE KEY-----');
    expect($arg)->toEndWith('-----END OPENSSH PRIVATE KEY-----"');
    // Should NOT have the escaped quote sequence that was in the bug
    expect($arg)->not->toContain("''");
    expect($arg)->not->toContain("'\\''");
});
