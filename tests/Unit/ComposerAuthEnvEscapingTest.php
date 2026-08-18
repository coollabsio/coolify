<?php

use App\Models\EnvironmentVariable;

/**
 * Tests for GitHub Issue #6160: COMPOSER_AUTH environment variable escaping.
 *
 * PR #6146 moved escaping into the EnvironmentVariable::realValue() accessor,
 * causing double-escaping for build-time vars and broken JSON for runtime vars.
 *
 * Fix: JSON objects/arrays detected in realValue() skip escaping entirely.
 */
const COMPOSER_AUTH_JSON = '{"http-basic":{"backpackforlaravel.com":{"username":"ourusername","password":"ourpassword"}}}';

// ---------------------------------------------------------------------------
// Test 1: realValue accessor returns raw JSON for non-literal env vars
// ---------------------------------------------------------------------------
it('realValue accessor returns raw JSON without escaping quotes', function () {
    $env = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $env->shouldReceive('relationLoaded')->with('resourceable')->andReturn(true);
    $env->shouldReceive('getAttribute')->with('resourceable')->andReturn(new stdClass);
    $env->shouldReceive('getAttribute')->with('value')->andReturn(COMPOSER_AUTH_JSON);
    $env->shouldReceive('getAttribute')->with('is_literal')->andReturn(false);
    $env->shouldReceive('getAttribute')->with('is_multiline')->andReturn(false);

    $realValue = $env->real_value;

    // JSON should pass through without escaping
    expect($realValue)->toBe(COMPOSER_AUTH_JSON);
    expect($realValue)->not->toContain('\\"');
});

// ---------------------------------------------------------------------------
// Test 2: realValue for a literal JSON env also returns raw JSON
// (JSON check fires before the literal single-quote wrapping)
// ---------------------------------------------------------------------------
it('realValue accessor for literal JSON env returns raw value without wrapping', function () {
    $env = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $env->shouldReceive('relationLoaded')->with('resourceable')->andReturn(true);
    $env->shouldReceive('getAttribute')->with('resourceable')->andReturn(new stdClass);
    $env->shouldReceive('getAttribute')->with('value')->andReturn(COMPOSER_AUTH_JSON);
    $env->shouldReceive('getAttribute')->with('is_literal')->andReturn(true);
    $env->shouldReceive('getAttribute')->with('is_multiline')->andReturn(false);

    $realValue = $env->real_value;

    // JSON check should fire first, returning raw JSON without single-quote wrapping
    expect($realValue)->toBe(COMPOSER_AUTH_JSON);
    expect($realValue)->not->toStartWith("'");
    expect($realValue)->not->toEndWith("'");
});

// ---------------------------------------------------------------------------
// Test 3: Non-JSON values still get normal escaping (regression check)
// ---------------------------------------------------------------------------
it('realValue accessor still escapes non-JSON values with quotes', function () {
    $env = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $env->shouldReceive('relationLoaded')->with('resourceable')->andReturn(true);
    $env->shouldReceive('getAttribute')->with('resourceable')->andReturn(new stdClass);
    $env->shouldReceive('getAttribute')->with('value')->andReturn('hello "world"');
    $env->shouldReceive('getAttribute')->with('is_literal')->andReturn(false);
    $env->shouldReceive('getAttribute')->with('is_multiline')->andReturn(false);

    $realValue = $env->real_value;

    // Non-JSON should still be escaped by escapeEnvVariables
    expect($realValue)->toContain('\\"');
    expect($realValue)->toBe('hello \\"world\\"');
});

// ---------------------------------------------------------------------------
// Test 4: JSON array values also skip escaping
// ---------------------------------------------------------------------------
it('realValue accessor returns raw JSON array without escaping', function () {
    $jsonArray = '[{"host":"example.com","token":"abc123"}]';

    $env = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $env->shouldReceive('relationLoaded')->with('resourceable')->andReturn(true);
    $env->shouldReceive('getAttribute')->with('resourceable')->andReturn(new stdClass);
    $env->shouldReceive('getAttribute')->with('value')->andReturn($jsonArray);
    $env->shouldReceive('getAttribute')->with('is_literal')->andReturn(false);
    $env->shouldReceive('getAttribute')->with('is_multiline')->andReturn(false);

    $realValue = $env->real_value;

    expect($realValue)->toBe($jsonArray);
    expect($realValue)->not->toContain('\\"');
});

// ---------------------------------------------------------------------------
// Test 5: Buildtime escaping of raw JSON produces recoverable value
// ---------------------------------------------------------------------------
it('escapeBashDoubleQuoted on raw JSON produces value recoverable as valid JSON', function () {
    $escaped = escapeBashDoubleQuoted(COMPOSER_AUTH_JSON);

    // Should be double-quoted
    expect($escaped)->toStartWith('"');
    expect($escaped)->toEndWith('"');

    // After bash unescaping (strip outer quotes, unescape \")
    $inner = substr($escaped, 1, -1);
    $inner = str_replace('\\"', '"', $inner);
    $decoded = json_decode($inner, true);

    expect($decoded)->not->toBeNull("Expected valid JSON after bash unescaping, got: {$inner}");
    expect($decoded)->toHaveKey('http-basic');
});
