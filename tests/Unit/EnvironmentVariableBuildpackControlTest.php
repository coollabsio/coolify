<?php

use App\Models\EnvironmentVariable;
use App\Models\SharedEnvironmentVariable;

it('flags NIXPACKS_ keys as buildpack control variables', function () {
    $env = new EnvironmentVariable;
    $env->key = 'NIXPACKS_NODE_VERSION';

    expect($env->is_buildpack_control)->toBeTrue();
});

it('flags RAILPACK_ keys as buildpack control variables', function () {
    $env = new EnvironmentVariable;
    $env->key = 'RAILPACK_NODE_VERSION';

    expect($env->is_buildpack_control)->toBeTrue();
});

it('does not flag user-defined keys as buildpack control variables', function () {
    $env = new EnvironmentVariable;
    $env->key = 'MY_BUILD_VAR';

    expect($env->is_buildpack_control)->toBeFalse();
});

it('does not flag empty key as buildpack control variable', function () {
    $env = new EnvironmentVariable;

    expect($env->is_buildpack_control)->toBeFalse();
});

it('lists is_buildpack_control in appends and drops legacy is_nixpacks', function () {
    $env = new EnvironmentVariable;

    expect($env->getAppends())->toContain('is_buildpack_control');
    expect($env->getAppends())->not->toContain('is_nixpacks');
});

it('normalizes environment variable keys before storing them on the model', function () {
    $env = new EnvironmentVariable;
    $env->key = ' node.name ';

    expect($env->key)->toBe('node.name');
});

it('allows Docker-compatible environment variable keys on the model', function (string $key) {
    $env = new EnvironmentVariable;
    $env->key = $key;

    expect($env->key)->toBe($key);
})->with([
    'starts with digit' => '1BAD',
    'hyphen' => 'BAD-KEY',
    'dot' => 'node.name',
    'uppercase dots' => 'XPACK.SECURITY.ENABLED',
    'semicolon' => 'BAD;KEY',
]);

it('rejects environment variable keys Docker cannot represent on the model', function () {
    $env = new EnvironmentVariable;

    expect(function () use ($env) {
        $env->key = 'BAD=KEY';
    })->toThrow(InvalidArgumentException::class, 'Docker-compatible');
});

it('rejects shared environment variable keys Docker cannot represent on the model', function () {
    $env = new SharedEnvironmentVariable;

    expect(function () use ($env) {
        $env->key = 'BAD=KEY';
    })->toThrow(InvalidArgumentException::class, 'Docker-compatible');
});
