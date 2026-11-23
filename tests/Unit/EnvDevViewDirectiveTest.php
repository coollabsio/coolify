<?php

use App\Models\EnvironmentVariable;
use App\Models\SharedEnvironmentVariable;
use Tests\TestCase;

uses(TestCase::class);

function devViewVariable(array $attributes): EnvironmentVariable
{
    return (new EnvironmentVariable)->forceFill(['key' => 'KEY', 'value' => 'value', ...$attributes]);
}

it('formats a directive line only for flags that are not their default', function (array $flags, string $expected) {
    expect(formatEnvVariableForDevView(devViewVariable($flags), true))->toBe($expected);
})->with([
    'defaults' => [['is_literal' => false, 'is_buildtime' => true, 'is_runtime' => true], 'KEY=value'],
    'literal' => [['is_literal' => true, 'is_buildtime' => true, 'is_runtime' => true], "# coolify: literal\nKEY=value"],
    'all non-default' => [['is_literal' => true, 'is_buildtime' => false, 'is_runtime' => false], "# coolify: literal buildtime=false runtime=false\nKEY=value"],
]);

it('formats only the literal flag for shared variables', function () {
    $variable = (new SharedEnvironmentVariable)->forceFill(['key' => 'KEY', 'value' => 'value', 'is_literal' => 1]);

    expect(formatEnvVariableForDevView($variable, true))->toBe("# coolify: literal\nKEY=value");
});

it('formats no directive above placeholder lines', function (array $attributes, string $expected, bool $canViewValue = true) {
    expect(formatEnvVariableForDevView(devViewVariable(['is_literal' => true, ...$attributes]), $canViewValue))->toBe($expected);
})->with([
    'hidden' => [[], 'KEY=(Hidden, only admins can view)', false],
    'locked' => [['is_shown_once' => true], 'KEY=(Locked Secret, delete and add again to change)'],
    'multiline' => [['is_multiline' => true], 'KEY=(Multiline environment variable, edit in normal view)'],
    'both quote types' => [['value' => '\'"a" b\''], 'KEY=(Value cannot be shown in this view, edit in normal view)'],
]);

it('reads back the flags and values that it formats', function (string $value, array $flags) {
    $text = formatEnvVariableForDevView(devViewVariable(['value' => $value, ...$flags]), true)
        ."\n".formatEnvVariableForDevView(devViewVariable(['key' => 'OTHER', 'value' => 'x']), true);

    $parsed = parseEnvDevView($text, EnvironmentVariable::DEV_VIEW_FLAG_DEFAULTS);

    expect($parsed['KEY']['value'])->toBe($value)
        ->and([...$flags, ...$parsed['KEY']['settings']])->toBe($flags)
        ->and($parsed['OTHER']['settings'])->toBe([]);
})->with([
    'plain' => 'plain',
    'double quoted' => '"abc"',
    'single quoted' => "'\$FOO'",
    'hash' => 'value #not-a-comment',
])->with([
    'defaults' => [['is_literal' => false, 'is_buildtime' => true, 'is_runtime' => true]],
    'literal' => [['is_literal' => true, 'is_buildtime' => true, 'is_runtime' => true]],
    'all non-default' => [['is_literal' => true, 'is_buildtime' => false, 'is_runtime' => false]],
]);

it('parses explicit and bare directive values', function () {
    $parsed = parseEnvDevView(
        "# coolify: literal=false buildtime runtime=true\nA=1   # a comment\n# other comment\nB=2",
        EnvironmentVariable::DEV_VIEW_FLAG_DEFAULTS,
    );

    expect($parsed)->toBe([
        'A' => ['value' => '1', 'comment' => 'a comment', 'settings' => ['is_literal' => false, 'is_buildtime' => true, 'is_runtime' => true]],
        'B' => ['value' => '2', 'comment' => null, 'settings' => []],
    ]);
});

it('rejects invalid directives', function (string $text, string $message) {
    expect(fn () => parseEnvDevView($text, EnvironmentVariable::DEV_VIEW_FLAG_DEFAULTS))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'last line' => ["A=1\n# coolify: literal", 'Line 2: a "# coolify:" line must be directly above a KEY=value line.'],
    'blank line before key' => ["# coolify: literal\n\nA=1", 'Line 1: a "# coolify:" line must be directly above a KEY=value line.'],
    'comment before key' => ["# coolify: literal\n# note\nA=1", 'Line 1: a "# coolify:" line must be directly above a KEY=value line.'],
    'two directives' => ["# coolify: literal\n# coolify: runtime=false\nA=1", 'Line 1: a "# coolify:" line must be directly above a KEY=value line.'],
    'no flags' => ["# coolify:\nA=1", 'Line 1: the "# coolify:" line has no flags.'],
    'unknown flag' => ["# coolify: multiline\nA=1", 'Line 1: unknown flag "multiline". Allowed flags: literal, buildtime, runtime.'],
    'bad value' => ["# coolify: literal=yes\nA=1", 'Line 1: the value of "literal" must be true or false.'],
]);

it('accepts only the literal flag for shared variables', function () {
    expect(parseEnvDevView("# coolify: literal\nA=1", SharedEnvironmentVariable::DEV_VIEW_FLAG_DEFAULTS)['A']['settings'])->toBe(['is_literal' => true])
        ->and(fn () => parseEnvDevView("# coolify: buildtime=false\nA=1", SharedEnvironmentVariable::DEV_VIEW_FLAG_DEFAULTS))
        ->toThrow(InvalidArgumentException::class, 'Line 1: unknown flag "buildtime". Allowed flags: literal.');
});

it('keeps ignoring directive lines in parseEnvFormatToArray', function () {
    expect(parseEnvFormatToArray("# coolify: literal\nA=1\n# coolify: bogus"))
        ->toBe(['A' => ['value' => '1', 'comment' => null]]);
});
