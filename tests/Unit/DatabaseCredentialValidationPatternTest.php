<?php

use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\Validator;

// ── DB_IDENTIFIER_PATTERN ─────────────────────────────────────────────────────

it('DB_IDENTIFIER_PATTERN accepts valid SQL identifiers', function (string $id) {
    expect(preg_match(ValidationPatterns::DB_IDENTIFIER_PATTERN, $id))->toBe(1);
})->with([
    'simple lowercase' => 'postgres',
    'underscore prefix' => '_admin',
    'mixed case' => 'MyDatabase',
    'alphanumeric' => 'App_DB_1',
    'single char' => 'a',
    'all caps' => 'ROOT',
    'numbers in middle' => 'db2user',
]);

it('DB_IDENTIFIER_PATTERN rejects shell-dangerous and invalid identifiers', function (string $id) {
    expect(preg_match(ValidationPatterns::DB_IDENTIFIER_PATTERN, $id))->toBe(0);
})->with([
    'semicolon' => 'user;id',
    'pipe' => 'user|cat',
    'ampersand' => 'user&rm',
    'dollar sign' => 'user$x',
    'backtick' => 'user`id`',
    'subshell' => 'user$(id)',
    'space' => 'user name',
    'newline' => "user\nname",
    'single quote' => "user'name",
    'double quote' => 'user"name',
    'backslash' => 'user\\name',
    'less than' => 'user<name',
    'greater than' => 'user>name',
    'leading digit' => '1user',
    'hyphen' => 'my-user',
    'dot' => 'my.user',
    'empty' => '',
    '64 chars (over limit)' => str_repeat('a', 64),
    'advisory poc payload' => 'root; touch /tmp/pwned_rce; #',
    'subshell payload' => 'a$(touch /tmp/pwn)b',
]);

// ── DB_PASSWORD_PATTERN ───────────────────────────────────────────────────────

it('DB_PASSWORD_PATTERN accepts strong passwords without shell-dangerous chars', function (string $pw) {
    expect(preg_match(ValidationPatterns::DB_PASSWORD_PATTERN, $pw))->toBe(1);
})->with([
    'alphanumeric' => 'SecurePass123',
    'with special safe chars' => 'P@ss!word#1',
    'with brackets' => 'P{a}ss[word]',
    'with slash' => 'Pass/word1',
    'with dot comma' => 'Pass.word,1',
    'with hyphen' => 'Pass-word1',
    'with plus equals' => 'Pass+word=1',
    'with tilde colon' => 'P~ass:word1',
    'complex strong' => 'Str0ng!P@ss#word^123',
]);

it('DB_PASSWORD_PATTERN rejects shell-dangerous characters', function (string $pw) {
    expect(preg_match(ValidationPatterns::DB_PASSWORD_PATTERN, $pw))->toBe(0);
})->with([
    'backtick' => 'pass`word`',
    'dollar sign' => 'pass$word',
    'semicolon' => 'pass;word',
    'pipe' => 'pass|word',
    'ampersand' => 'pass&word',
    'less than' => 'pass<word',
    'greater than' => 'pass>word',
    'backslash' => 'pass\\word',
    'single quote' => "pass'word",
    'double quote' => 'pass"word',
    'space' => 'pass word',
    'newline' => "pass\nword",
    'carriage return' => "pass\rword",
    'tab' => "pass\tword",
    'empty' => '',
    'command substitution' => '$(whoami)',
    'rce payload' => 'root; touch /tmp/pwned; #',
]);

// ── Rule helpers ──────────────────────────────────────────────────────────────

it('databaseIdentifierRules returns required by default', function () {
    $rules = ValidationPatterns::databaseIdentifierRules();

    expect($rules)->toContain('required')
        ->toContain('string')
        ->toContain('min:1')
        ->toContain('max:63')
        ->toContain('regex:'.ValidationPatterns::DB_IDENTIFIER_PATTERN);
});

it('databaseIdentifierRules returns nullable when not required', function () {
    $rules = ValidationPatterns::databaseIdentifierRules(required: false);

    expect($rules)->toContain('nullable')
        ->not->toContain('required');
});

it('databasePasswordRules returns required by default', function () {
    $rules = ValidationPatterns::databasePasswordRules();

    expect($rules)->toContain('required')
        ->toContain('string')
        ->toContain('min:1')
        ->toContain('max:128')
        ->toContain('regex:'.ValidationPatterns::DB_PASSWORD_PATTERN);
});

it('databasePasswordRules returns nullable when not required', function () {
    $rules = ValidationPatterns::databasePasswordRules(required: false);

    expect($rules)->toContain('nullable')
        ->not->toContain('required');
});

it('isValidDatabaseIdentifier returns true for valid identifier', function () {
    expect(ValidationPatterns::isValidDatabaseIdentifier('postgres'))->toBeTrue();
    expect(ValidationPatterns::isValidDatabaseIdentifier('_admin'))->toBeTrue();
    expect(ValidationPatterns::isValidDatabaseIdentifier('DB_1'))->toBeTrue();
});

it('isValidDatabaseIdentifier returns false for injection payloads', function () {
    expect(ValidationPatterns::isValidDatabaseIdentifier('user; id'))->toBeFalse();
    expect(ValidationPatterns::isValidDatabaseIdentifier('user$(whoami)'))->toBeFalse();
    expect(ValidationPatterns::isValidDatabaseIdentifier(''))->toBeFalse();
});

// ── Validator integration ─────────────────────────────────────────────────────

it('Laravel Validator rejects advisory PoC postgres_user payload', function () {
    $validator = Validator::make(
        ['postgres_user' => 'root; touch /tmp/pwned_rce; #'],
        ['postgres_user' => ValidationPatterns::databaseIdentifierRules()]
    );

    expect($validator->fails())->toBeTrue();
});

it('Laravel Validator rejects subshell injection in postgres_user', function () {
    $validator = Validator::make(
        ['postgres_user' => 'a$(touch /tmp/pwn)b'],
        ['postgres_user' => ValidationPatterns::databaseIdentifierRules()]
    );

    expect($validator->fails())->toBeTrue();
});

it('Laravel Validator accepts clean postgres_user', function () {
    $validator = Validator::make(
        ['postgres_user' => 'postgres'],
        ['postgres_user' => ValidationPatterns::databaseIdentifierRules()]
    );

    expect($validator->fails())->toBeFalse();
});

it('Laravel Validator rejects shell metachar in password', function () {
    $validator = Validator::make(
        ['postgres_password' => 'pass$(id)word'],
        ['postgres_password' => ValidationPatterns::databasePasswordRules()]
    );

    expect($validator->fails())->toBeTrue();
});

it('Laravel Validator accepts safe password', function () {
    $validator = Validator::make(
        ['postgres_password' => 'Str0ng!P@ss#123'],
        ['postgres_password' => ValidationPatterns::databasePasswordRules()]
    );

    expect($validator->fails())->toBeFalse();
});
