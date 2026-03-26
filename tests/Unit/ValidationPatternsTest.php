<?php

use App\Support\ValidationPatterns;

it('accepts valid names with common characters', function (string $name) {
    expect(preg_match(ValidationPatterns::NAME_PATTERN, $name))->toBe(1);
})->with([
    'simple name' => 'My Server',
    'name with hyphen' => 'my-server',
    'name with underscore' => 'my_server',
    'name with dot' => 'my.server',
    'name with slash' => 'my/server',
    'name with at sign' => 'user@host',
    'name with ampersand' => 'Tom & Jerry',
    'name with parentheses' => 'My Server (Production)',
    'name with hash' => 'Server #1',
    'name with comma' => 'Server, v2',
    'name with colon' => 'Server: Production',
    'name with plus' => 'C++ App',
    'unicode name' => 'Ünïcödé Sërvér',
    'unicode chinese' => '我的服务器',
    'numeric name' => '12345',
    'complex name' => 'App #3 (staging): v2.1+hotfix',
]);

it('rejects names with dangerous characters', function (string $name) {
    expect(preg_match(ValidationPatterns::NAME_PATTERN, $name))->toBe(0);
})->with([
    'semicolon' => 'my;server',
    'pipe' => 'my|server',
    'dollar sign' => 'my$server',
    'backtick' => 'my`server',
    'backslash' => 'my\\server',
    'less than' => 'my<server',
    'greater than' => 'my>server',
    'curly braces' => 'my{server}',
    'square brackets' => 'my[server]',
    'tilde' => 'my~server',
    'caret' => 'my^server',
    'question mark' => 'my?server',
    'percent' => 'my%server',
    'double quote' => 'my"server',
    'exclamation' => 'my!server',
    'asterisk' => 'my*server',
]);

it('generates nameRules with correct defaults', function () {
    $rules = ValidationPatterns::nameRules();

    expect($rules)->toContain('required')
        ->toContain('string')
        ->toContain('min:3')
        ->toContain('max:255')
        ->toContain('regex:'.ValidationPatterns::NAME_PATTERN);
});

it('generates nullable nameRules when not required', function () {
    $rules = ValidationPatterns::nameRules(required: false);

    expect($rules)->toContain('nullable')
        ->not->toContain('required');
});

it('generates application names that comply with NAME_PATTERN', function (string $repo, string $branch) {
    $name = generate_application_name($repo, $branch, 'testcuid');

    expect(preg_match(ValidationPatterns::NAME_PATTERN, $name))->toBe(1);
})->with([
    'normal repo' => ['owner/my-app', 'main'],
    'repo with dots' => ['repo.with.dots', 'feat/branch'],
    'repo with plus' => ['C++ App', 'main'],
    'branch with parens' => ['my-app', 'fix(auth)-login'],
    'repo with exclamation' => ['my-app!', 'main'],
    'repo with brackets' => ['app[test]', 'develop'],
]);

it('falls back to random name when repo produces empty name', function () {
    $name = generate_application_name('!!!', 'main', 'testcuid');

    expect(mb_strlen($name))->toBeGreaterThanOrEqual(3)
        ->and(preg_match(ValidationPatterns::NAME_PATTERN, $name))->toBe(1);
});
