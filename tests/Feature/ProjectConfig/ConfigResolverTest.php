<?php

use App\Services\ProjectConfig\ConfigException;
use App\Services\ProjectConfig\ConfigParser;
use App\Services\ProjectConfig\ConfigResolver;
use App\Services\ProjectConfig\ResolvedEnvironment;

function resolverTestResolve(array $file, string $branch = 'main'): ResolvedEnvironment
{
    $file += ['version' => 1];
    $doc = app(ConfigParser::class)->parse(json_encode($file));

    return app(ConfigResolver::class)->forBranch($doc, $branch);
}

function resolverTestErrorPaths(array $file, string $branch = 'main'): array
{
    try {
        resolverTestResolve($file, $branch);
    } catch (ConfigException $e) {
        return array_map(fn ($error) => $error->path, $e->errors());
    }

    return [];
}

function resolverTestBase(array $staging = []): array
{
    return [
        'resources' => [
            'api' => [
                'type' => 'application',
                'server' => 'prod-1',
                'domains' => ['https://api.acme.com'],
                'health_check' => ['enabled' => true, 'path' => '/health'],
                'env' => ['NODE_ENV' => ['value' => 'production'], 'DEBUG' => ['value' => '0']],
            ],
        ],
        'environments' => [
            'production' => ['branch' => 'main'],
            'staging' => ['branch' => 'staging'] + $staging,
        ],
    ];
}

it('returns the base resources for an environment without overrides', function () {
    $env = resolverTestResolve(resolverTestBase());

    expect($env->name)->toBe('production')
        ->and($env->branch)->toBe('main')
        ->and($env->resources['api']['server'])->toBe('prod-1');
});

it('merges objects by key and replaces lists', function () {
    $env = resolverTestResolve(resolverTestBase(['resources' => ['api' => [
        'server' => 'stage-1',
        'domains' => ['https://staging.acme.com'],
        'health_check' => ['path' => '/ready'],
    ]]]), 'staging');

    expect($env->resources['api']['server'])->toBe('stage-1')
        ->and($env->resources['api']['domains'])->toBe(['https://staging.acme.com'])
        ->and($env->resources['api']['health_check'])->toBe(['enabled' => true, 'path' => '/ready']);
});

it('removes an inherited value with null, also a variable', function () {
    $env = resolverTestResolve(resolverTestBase(['resources' => ['api' => [
        'health_check' => null,
        'env' => ['DEBUG' => null],
    ]]]), 'staging');

    expect($env->resources['api'])->not->toHaveKey('health_check')
        ->and($env->resources['api']['env'])->toBe(['NODE_ENV' => ['value' => 'production']]);
});

it('replaces a whole variable entry instead of merging its forms', function () {
    $env = resolverTestResolve(resolverTestBase(['resources' => ['api' => [
        'env' => ['NODE_ENV' => ['required' => true]],
    ]]]), 'staging');

    expect($env->resources['api']['env']['NODE_ENV'])->toBe(['required' => true]);
});

it('adds a resource for one environment and drops one with enabled false', function () {
    $file = resolverTestBase(['resources' => [
        'api' => ['enabled' => false],
        'mailpit' => ['type' => 'service', 'server' => 'stage-1', 'template' => 'mailpit'],
    ]]);

    expect(array_keys(resolverTestResolve($file, 'staging')->resources))->toBe(['mailpit'])
        ->and(array_keys(resolverTestResolve($file)->resources))->toBe(['api']);
});

it('removes the enabled key from a kept resource', function () {
    $env = resolverTestResolve(resolverTestBase(['resources' => ['api' => ['enabled' => true]]]), 'staging');

    expect($env->resources['api'])->not->toHaveKey('enabled');
});

it('fails for a branch with no environment', function () {
    try {
        resolverTestResolve(resolverTestBase(), 'feature-x');
        $this->fail('No exception');
    } catch (ConfigException $e) {
        expect($e->errors()[0]->path)->toBe('environments')
            ->and($e->errors()[0]->message)->toContain('feature-x');
    }
});

it('resolves an environment by name', function () {
    $doc = app(ConfigParser::class)->parse(json_encode(resolverTestBase() + ['version' => 1]));

    expect(app(ConfigResolver::class)->forEnvironment($doc, 'staging')->branch)->toBe('staging');
});

it('orders creates so referenced resources come first', function () {
    $file = [
        'resources' => [
            'api' => ['type' => 'application', 'env' => ['DATABASE_URL' => ['ref' => 'resource.db.internal_url']]],
            'worker' => ['type' => 'application', 'env' => ['API' => ['ref' => 'resource.api.internal_url']]],
            'db' => ['type' => 'postgresql'],
            'cache' => ['type' => 'redis'],
        ],
        'environments' => ['production' => ['branch' => 'main']],
    ];

    expect(array_keys(resolverTestResolve($file)->resources))->toBe(['db', 'api', 'worker', 'cache']);
});

it('fails on a reference cycle and on a missing reference target', function () {
    $cycle = [
        'resources' => [
            'a' => ['type' => 'application', 'env' => ['B' => ['ref' => 'resource.b.host']]],
            'b' => ['type' => 'application', 'env' => ['A' => ['ref' => 'resource.a.host']]],
        ],
        'environments' => ['production' => ['branch' => 'main']],
    ];
    $missing = [
        'resources' => ['a' => ['type' => 'application', 'env' => ['X' => ['ref' => 'resource.nope.host']]]],
        'environments' => ['production' => ['branch' => 'main']],
    ];

    expect(resolverTestErrorPaths($cycle))->toContain('resources.a.env.B')
        ->and(resolverTestErrorPaths($missing))->toBe(['resources.a.env.X']);
});

it('rejects an override that changes the type or adds a resource without one', function () {
    expect(resolverTestErrorPaths(resolverTestBase(['resources' => ['api' => ['type' => 'service']]]), 'staging'))->toBe(['environments.staging.resources.api.type'])
        ->and(resolverTestErrorPaths(resolverTestBase(['resources' => ['new' => ['server' => 'x']]]), 'staging'))->toBe(['environments.staging.resources.new.type']);
});

it('passes moved, unmanage, and imports through', function () {
    $file = resolverTestBase(['import' => [['to' => 'api', 'uuid' => 'k8s0abc']]]) + ['moved' => [['from' => 'web', 'to' => 'api']], 'unmanage' => ['old']];
    $env = resolverTestResolve($file, 'staging');

    expect($env->moved)->toBe([['from' => 'web', 'to' => 'api']])
        ->and($env->unmanage)->toBe(['old'])
        ->and($env->imports)->toBe([['to' => 'api', 'uuid' => 'k8s0abc']]);
});

it('fails when an import names a key that is not a resource of the environment', function () {
    expect(resolverTestErrorPaths(resolverTestBase(['import' => [['to' => 'nope', 'uuid' => 'x']]]), 'staging'))->toBe(['environments.staging.import.0.to']);
});
