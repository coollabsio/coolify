<?php

use App\Support\ValidationPatterns;

// ── databasePasswordRules ─────────────────────────────────────────────────────

it('databasePasswordRules includes regex rule by default', function () {
    $rules = ValidationPatterns::databasePasswordRules();

    $regexRules = array_filter($rules, fn ($rule) => str_starts_with($rule, 'regex:'));
    expect($regexRules)->not->toBeEmpty();
});

it('databasePasswordRules includes regex rule when enforcePattern true', function () {
    $rules = ValidationPatterns::databasePasswordRules(enforcePattern: true);

    $regexRules = array_filter($rules, fn ($rule) => str_starts_with($rule, 'regex:'));
    expect($regexRules)->not->toBeEmpty();
});

it('databasePasswordRules omits regex rule when enforcePattern false', function () {
    $rules = ValidationPatterns::databasePasswordRules(enforcePattern: false);

    $regexRules = array_filter($rules, fn ($rule) => str_starts_with($rule, 'regex:'));
    expect($regexRules)->toBeEmpty();
});

it('databasePasswordRules keeps required, string, min and max when enforcePattern false', function () {
    $rules = ValidationPatterns::databasePasswordRules(required: true, minLength: 1, maxLength: 128, enforcePattern: false);

    expect($rules)->toContain('required');
    expect($rules)->toContain('string');
    expect($rules)->toContain('min:1');
    expect($rules)->toContain('max:128');
});

it('databasePasswordRules keeps nullable and bounds when not required and enforcePattern false', function () {
    $rules = ValidationPatterns::databasePasswordRules(required: false, minLength: 2, maxLength: 64, enforcePattern: false);

    expect($rules)->toContain('nullable');
    expect($rules)->toContain('string');
    expect($rules)->toContain('min:2');
    expect($rules)->toContain('max:64');
    expect(array_filter($rules, fn ($rule) => str_starts_with($rule, 'regex:')))->toBeEmpty();
});

// ── databaseIdentifierRules ───────────────────────────────────────────────────

it('databaseIdentifierRules includes regex rule by default', function () {
    $rules = ValidationPatterns::databaseIdentifierRules();

    $regexRules = array_filter($rules, fn ($rule) => str_starts_with($rule, 'regex:'));
    expect($regexRules)->not->toBeEmpty();
});

it('databaseIdentifierRules includes regex rule when enforcePattern true', function () {
    $rules = ValidationPatterns::databaseIdentifierRules(enforcePattern: true);

    $regexRules = array_filter($rules, fn ($rule) => str_starts_with($rule, 'regex:'));
    expect($regexRules)->not->toBeEmpty();
});

it('databaseIdentifierRules omits regex rule when enforcePattern false', function () {
    $rules = ValidationPatterns::databaseIdentifierRules(enforcePattern: false);

    $regexRules = array_filter($rules, fn ($rule) => str_starts_with($rule, 'regex:'));
    expect($regexRules)->toBeEmpty();
});

it('databaseIdentifierRules keeps required, string, min and max when enforcePattern false', function () {
    $rules = ValidationPatterns::databaseIdentifierRules(required: true, minLength: 1, maxLength: 63, enforcePattern: false);

    expect($rules)->toContain('required');
    expect($rules)->toContain('string');
    expect($rules)->toContain('min:1');
    expect($rules)->toContain('max:63');
});

it('databaseIdentifierRules keeps nullable and bounds when not required and enforcePattern false', function () {
    $rules = ValidationPatterns::databaseIdentifierRules(required: false, minLength: 1, maxLength: 30, enforcePattern: false);

    expect($rules)->toContain('nullable');
    expect($rules)->toContain('string');
    expect($rules)->toContain('min:1');
    expect($rules)->toContain('max:30');
    expect(array_filter($rules, fn ($rule) => str_starts_with($rule, 'regex:')))->toBeEmpty();
});
