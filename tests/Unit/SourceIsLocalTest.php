<?php

test('sourceIsLocal recognizes bare dot as local path', function () {
    expect(sourceIsLocal(str('.')))->toBeTrue();
});

test('sourceIsLocal recognizes dot-slash as local path', function () {
    expect(sourceIsLocal(str('./data')))->toBeTrue();
});

test('sourceIsLocal recognizes absolute paths as local', function () {
    expect(sourceIsLocal(str('/var/data')))->toBeTrue();
});

test('sourceIsLocal recognizes tilde paths as local', function () {
    expect(sourceIsLocal(str('~/data')))->toBeTrue();
    expect(sourceIsLocal(str('~')))->toBeTrue();
});

test('sourceIsLocal recognizes double-dot paths as local', function () {
    expect(sourceIsLocal(str('../data')))->toBeTrue();
    expect(sourceIsLocal(str('..')))->toBeTrue();
});

test('sourceIsLocal rejects named volumes', function () {
    expect(sourceIsLocal(str('my_volume')))->toBeFalse();
    expect(sourceIsLocal(str('data')))->toBeFalse();
});

test('resolveEnvVarDefault bare dot fallback is recognized as local by sourceIsLocal', function () {
    // When no env var is set and no default exists, resolveEnvVarDefault returns "."
    $resolved = resolveEnvVarDefault(str('${UNDEFINED_VAR}'), collect());
    expect(sourceIsLocal($resolved))->toBeTrue();
});

test('resolveEnvVarDefault with fallback path is recognized as local', function () {
    // ${VAR:-./agent-orchestrator.yaml} with no env var set should resolve to the default
    $resolved = resolveEnvVarDefault(str('${AO_CONFIG_FILE:-./agent-orchestrator.yaml}'), collect());
    expect($resolved->value())->toBe('./agent-orchestrator.yaml');
    expect(sourceIsLocal($resolved))->toBeTrue();
});
