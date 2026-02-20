<?php

use Illuminate\Support\Collection;

beforeAll(function () {
    if (! function_exists('normalizeExplicitEnvFiles')) {
        require_once __DIR__.'/../../bootstrap/helpers/parsers.php';
    }
});

it('normalizes explicit env_file values without injecting implicit defaults', function () {
    $normalized = normalizeExplicitEnvFiles([' service.env ', '.env', '', null, '.env']);

    expect($normalized)
        ->toBeInstanceOf(Collection::class)
        ->toHaveCount(2)
        ->and($normalized->all())->toBe(['service.env', '.env']);
});

it('returns empty collection when env_file is missing', function () {
    $normalized = normalizeExplicitEnvFiles(null);

    expect($normalized)
        ->toBeInstanceOf(Collection::class)
        ->toHaveCount(0)
        ->and($normalized->all())->toBe([]);
});

it('filters non-string values from env_file definitions', function () {
    $normalized = normalizeExplicitEnvFiles(['.env', 42, true, ['nested'], 'runtime.env']);

    expect($normalized->all())->toBe(['.env', 'runtime.env']);
});

it('uses normalizeExplicitEnvFiles in both parser flows and removes implicit push', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    expect(substr_count($parsersFile, "normalizeExplicitEnvFiles(data_get(\$service, 'env_file'))"))
        ->toBe(2)
        ->and($parsersFile)->not->toContain("->push('.env')");
});
