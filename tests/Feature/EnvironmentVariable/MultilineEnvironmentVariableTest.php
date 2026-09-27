<?php

test('generateDockerBuildArgs returns only keys without values', function () {
    $variables = [
        ['key' => 'SSH_PRIVATE_KEY', 'value' => "'some-ssh-key'", 'is_multiline' => true],
        ['key' => 'REGULAR_VAR', 'value' => 'simple value', 'is_multiline' => false],
    ];

    $buildArgs = generateDockerBuildArgs($variables);

    // Docker gets values from the environment, so only keys should be in build args
    expect($buildArgs->first())->toBe("--build-arg 'SSH_PRIVATE_KEY'");
    expect($buildArgs->last())->toBe("--build-arg 'REGULAR_VAR'");
});

test('generateDockerBuildArgs works with collection of objects', function () {
    $variables = collect([
        (object) ['key' => 'VAR1', 'value' => 'value1', 'is_multiline' => false],
        (object) ['key' => 'VAR2', 'value' => "'multiline\nvalue'", 'is_multiline' => true],
    ]);

    $buildArgs = generateDockerBuildArgs($variables);
    expect($buildArgs)->toHaveCount(2);
    expect($buildArgs->values()->toArray())->toBe([
        "--build-arg 'VAR1'",
        "--build-arg 'VAR2'",
    ]);
});

test('generateDockerBuildArgs collection can be imploded into valid command string', function () {
    $variables = [
        ['key' => 'COOLIFY_URL', 'value' => 'http://example.com', 'is_multiline' => false],
        ['key' => 'COOLIFY_BRANCH', 'value' => 'main', 'is_multiline' => false],
    ];

    $buildArgs = generateDockerBuildArgs($variables);

    // The collection must be imploded to a string for command interpolation
    // This was the bug: Collection was interpolated as JSON instead of a space-separated string
    $argsString = $buildArgs->implode(' ');
    expect($argsString)->toBe("--build-arg 'COOLIFY_URL' --build-arg 'COOLIFY_BRANCH'");

    // Verify it does NOT produce JSON when cast to string
    expect($argsString)->not->toContain('{');
    expect($argsString)->not->toContain('}');
});

test('generateDockerBuildArgs handles variables without is_multiline', function () {
    $variables = [
        ['key' => 'NO_FLAG_VAR', 'value' => 'some value'],
    ];

    $buildArgs = generateDockerBuildArgs($variables);
    $arg = $buildArgs->first();

    expect($arg)->toBe("--build-arg 'NO_FLAG_VAR'");
});

test('generateDockerEnvFlags produces correct format', function () {
    $variables = [
        ['key' => 'NORMAL_VAR', 'value' => 'value', 'is_multiline' => false],
        ['key' => 'MULTILINE_VAR', 'value' => "'line1\nline2'", 'is_multiline' => true],
    ];

    $envFlags = generateDockerEnvFlags($variables);

    expect($envFlags)->toContain("-e 'NORMAL_VAR=value'");
    expect($envFlags)->toContain("-e 'MULTILINE_VAR=line1");
    expect($envFlags)->toContain('line1');
    expect($envFlags)->toContain('line2');
});

test('generateDockerEnvFlags works with collection input', function () {
    $variables = collect([
        (object) ['key' => 'VAR1', 'value' => 'value1', 'is_multiline' => false],
        (object) ['key' => 'VAR2', 'value' => "'multiline\nvalue'", 'is_multiline' => true],
    ]);

    $envFlags = generateDockerEnvFlags($variables);
    expect($envFlags)->toBeString();
    expect($envFlags)->toContain("-e 'VAR1=value1'");
    expect($envFlags)->toContain("-e 'VAR2=multiline");
});

test('docker argument helpers reject unsafe legacy keys', function (string $key) {
    $variables = [['key' => $key, 'value' => '1']];

    expect(fn () => generateDockerBuildArgs($variables))->toThrow(InvalidArgumentException::class);
    expect(fn () => generateDockerEnvFlags($variables))->toThrow(InvalidArgumentException::class);
})->with([
    'semicolon' => 'BAD;id',
    'command substitution' => 'BAD$(id)',
    'backticks' => 'BAD`id`',
    'pipe' => 'BAD|id',
    'logical operator' => 'BAD&&id',
    'single quote' => "BAD'KEY",
    'double quote' => 'BAD"KEY',
    'backslash' => 'BAD\\KEY',
    'space' => 'BAD KEY',
    'newline' => "BAD\nKEY",
    'control character' => "BAD\x1BKEY",
    'equals sign' => 'BAD=KEY',
    'option prefix' => '--BAD',
    'parameter expansion' => 'BAD${PATH}',
    'braces' => 'BAD{KEY}',
    'unicode lookalike' => 'BＡD',
    'empty' => '',
    'leading digit' => '1BAD',
]);

test('generateDockerEnvFlags quotes the complete assignment', function () {
    $flags = generateDockerEnvFlags([
        ['key' => 'NORMAL_VAR', 'value' => 'value with spaces', 'is_multiline' => false],
        ['key' => 'MULTILINE_VAR', 'value' => "line1\nline2", 'is_multiline' => true],
    ]);

    expect($flags)->toBe("-e 'NORMAL_VAR=value with spaces' -e 'MULTILINE_VAR=line1\nline2'");
});
