<?php

use App\Rules\ValidDockerBuildCacheConfiguration;
use App\Services\DockerBuildCacheConfiguration;
use Illuminate\Support\Facades\Validator;

uses(Tests\TestCase::class);

function registryCacheConfiguration(string $reference = 'registry.example.com/team/app:buildcache'): array
{
    return [
        'enabled' => true,
        'cache_from' => [
            'type' => 'registry',
            'value' => $reference,
        ],
        'cache_to' => [
            'type' => 'registry',
            'value' => $reference,
        ],
        'failure_policy' => 'continue',
    ];
}

test('registry cache configuration produces buildx arguments with max export mode', function () {
    $configuration = DockerBuildCacheConfiguration::resolve(
        production: registryCacheConfiguration(),
        preview: null,
        isPreview: false,
    );

    expect($configuration)
        ->not->toBeNull()
        ->and($configuration->buildArguments(forceRebuild: false))->toBe([
            '--cache-from type=registry,ref=registry.example.com/team/app:buildcache',
            '--cache-to type=registry,ref=registry.example.com/team/app:buildcache,mode=max',
        ])
        ->and($configuration->shouldFail())->toBeFalse()
        ->and($configuration->usesLocalCache())->toBeFalse();
});

test('preview cache inherits production configuration by default', function () {
    $configuration = DockerBuildCacheConfiguration::resolve(
        production: registryCacheConfiguration(),
        preview: null,
        isPreview: true,
    );

    expect($configuration)
        ->not->toBeNull()
        ->and($configuration->configurationSource())->toBe('production')
        ->and($configuration->deploymentContext())->toBe('preview');
});

test('preview cache can explicitly disable inherited configuration', function () {
    $configuration = DockerBuildCacheConfiguration::resolve(
        production: registryCacheConfiguration(),
        preview: ['enabled' => false],
        isPreview: true,
    );

    expect($configuration)->toBeNull();
});

test('force rebuild skips cache import but still exports a fresh cache', function () {
    $configuration = DockerBuildCacheConfiguration::resolve(
        production: registryCacheConfiguration(),
        preview: null,
        isPreview: false,
    );

    expect($configuration?->buildArguments(forceRebuild: true))->toBe([
        '--cache-to type=registry,ref=registry.example.com/team/app:buildcache,mode=max',
    ]);
});

test('advanced raw cache values are passed through and local caches are detected', function () {
    $configuration = DockerBuildCacheConfiguration::resolve(
        production: [
            'enabled' => true,
            'cache_from' => [
                'type' => 'raw',
                'value' => 'type=local,src=/cache',
            ],
            'cache_to' => [
                'type' => 'raw',
                'value' => 'type=local,dest=/cache,mode=max',
            ],
            'failure_policy' => 'fail',
        ],
        preview: null,
        isPreview: false,
    );

    expect($configuration)
        ->not->toBeNull()
        ->and($configuration->buildArguments(forceRebuild: false))->toBe([
            '--cache-from type=local,src=/cache',
            '--cache-to type=local,dest=/cache,mode=max',
        ])
        ->and($configuration->shouldFail())->toBeTrue()
        ->and($configuration->usesLocalCache())->toBeTrue();
});

test('valid cache configuration passes validation', function () {
    $validator = Validator::make(
        ['docker_build_cache' => registryCacheConfiguration()],
        ['docker_build_cache' => ['nullable', new ValidDockerBuildCacheConfiguration]],
    );

    expect($validator->passes())->toBeTrue();
});

it('requires an explicit registry reference', function () {
    $configuration = registryCacheConfiguration(reference: '');

    $validator = Validator::make(
        ['docker_build_cache' => $configuration],
        ['docker_build_cache' => [new ValidDockerBuildCacheConfiguration]],
    );

    expect($validator->fails())->toBeTrue();
});

it('rejects unsafe advanced raw values', function (string $value) {
    $configuration = registryCacheConfiguration();
    $configuration['cache_from'] = ['type' => 'raw', 'value' => $value];

    $validator = Validator::make(
        ['docker_build_cache' => $configuration],
        ['docker_build_cache' => [new ValidDockerBuildCacheConfiguration]],
    );

    expect($validator->fails())->toBeTrue();
})->with([
    'shell command separator' => 'type=registry,ref=example.com/cache;whoami',
    'command substitution' => 'type=registry,ref=$(whoami)',
    'line break' => "type=registry,ref=example.com/cache\n--push",
]);

it('requires local raw cache endpoints to use the mounted cache directory', function (string $endpoint, string $value) {
    $configuration = [
        'enabled' => true,
        'cache_from' => ['type' => 'raw', 'value' => 'type=local,src=/cache'],
        'cache_to' => ['type' => 'raw', 'value' => 'type=local,dest=/cache,mode=max'],
        'failure_policy' => 'continue',
    ];
    $configuration[$endpoint]['value'] = $value;

    $validator = Validator::make(
        ['docker_build_cache' => $configuration],
        ['docker_build_cache' => [new ValidDockerBuildCacheConfiguration]],
    );

    expect($validator->fails())->toBeTrue();
})->with([
    'source outside mount' => ['cache_from', 'type=local,src=/tmp/cache'],
    'destination outside mount' => ['cache_to', 'type=local,dest=/tmp/cache'],
]);

it('allows local preview caches to use a subdirectory of the mounted cache directory', function () {
    $configuration = [
        'enabled' => true,
        'cache_from' => ['type' => 'raw', 'value' => 'type=local,src=/cache/previews'],
        'cache_to' => ['type' => 'raw', 'value' => 'type=local,dest=/cache/previews,mode=max'],
        'failure_policy' => 'continue',
    ];

    $validator = Validator::make(
        ['preview_docker_build_cache' => $configuration],
        ['preview_docker_build_cache' => [new ValidDockerBuildCacheConfiguration]],
    );

    expect($validator->passes())->toBeTrue();
});

it('only permits disabled preview configuration without cache endpoints', function () {
    $validator = Validator::make(
        ['preview_docker_build_cache' => ['enabled' => false]],
        ['preview_docker_build_cache' => [new ValidDockerBuildCacheConfiguration]],
    );

    expect($validator->passes())->toBeTrue();
});

it('requires the enabled flag to be a JSON boolean', function () {
    $configuration = registryCacheConfiguration();
    $configuration['enabled'] = 1;

    $validator = Validator::make(
        ['docker_build_cache' => $configuration],
        ['docker_build_cache' => [new ValidDockerBuildCacheConfiguration]],
    );

    expect($validator->fails())->toBeTrue();
});
