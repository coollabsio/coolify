<?php

it('does not auto-inject .env into env_file', function () {
    $parsersFile = file_get_contents(__DIR__ . '/../../bootstrap/helpers/parsers.php');

    // Old vulnerable behavior must not exist
    expect($parsersFile)->not->toContain("->push('.env')");
    expect($parsersFile)->not->toContain('->push(\'.env\')');
});

it('filters .env from env_file entries', function () {
    $existingEnvFiles = ['./custom.env', '.env', './another.env'];

    $envFiles = collect($existingEnvFiles)
        ->filter(fn ($file) => $file !== '.env')
        ->values();

    expect($envFiles->toArray())->toBe(['./custom.env', './another.env']);
});

it('handles null env_file gracefully', function () {
    $existingEnvFiles = null;

    if ($existingEnvFiles !== null) {
        $envFiles = collect(is_array($existingEnvFiles) ? $existingEnvFiles : [$existingEnvFiles])
            ->filter(fn ($file) => $file !== '.env')
            ->values();

        $result = $envFiles->isNotEmpty() ? $envFiles->toArray() : null;
    } else {
        $result = null;
    }

    expect($result)->toBeNull();
});

it('handles empty env_file array gracefully', function () {
    $existingEnvFiles = [];

    $envFiles = collect($existingEnvFiles)
        ->filter(fn ($file) => $file !== '.env')
        ->values();

    expect($envFiles->isEmpty())->toBeTrue();
});

it('handles env_file with only .env', function () {
    $existingEnvFiles = ['.env'];

    $envFiles = collect($existingEnvFiles)
        ->filter(fn ($file) => $file !== '.env')
        ->values();

    expect($envFiles->isEmpty())->toBeTrue();
});

it('handles string env_file value', function () {
    $existingEnvFiles = './custom.env';

    $envFiles = collect(is_array($existingEnvFiles) ? $existingEnvFiles : [$existingEnvFiles])
        ->filter(fn ($file) => $file !== '.env')
        ->values();

    expect($envFiles->toArray())->toBe(['./custom.env']);
});

it('still sets environment variables per service', function () {
    $parsersFile = file_get_contents(__DIR__ . '/../../bootstrap/helpers/parsers.php');

    expect($parsersFile)->toContain("\$payload['environment'] = \$environment->merge");
});
