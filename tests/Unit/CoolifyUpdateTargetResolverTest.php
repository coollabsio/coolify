<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\CoolifyUpdateTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'constants.coolify.latest_image' => 'next',
        'constants.coolify.registry_url' => 'docker.io',
    ]);

    InstanceSettings::forceCreate([
        'id' => 0,
        'docker_registry_url' => 'docker.io',
    ]);
});

it('resolves the immutable version from the selected remote platform without pulling', function () {
    $command = null;
    $resolver = new CoolifyUpdateTargetResolver(function (array $commands, Server $server) use (&$command): string {
        $command = implode("\n", $commands);

        return json_encode([
            'linux/amd64' => [
                'config' => ['Env' => ['COOLIFY_VERSION=4.5-rc.1.def5678']],
            ],
            'linux/arm64' => [
                'Config' => ['Env' => ['COOLIFY_VERSION=4.5-rc.1.abc1234']],
            ],
        ], JSON_THROW_ON_ERROR);
    });

    $server = new Server([
        'server_metadata' => ['arch' => 'aarch64'],
    ]);

    expect($resolver->resolve($server))->toBe('4.5-rc.1.abc1234')
        ->and($command)
        ->toContain('docker run --rm')
        ->toContain('docker.io/coollabsio/coolify-helper:1.0.17')
        ->toContain('/root/.docker/config.json:/root/.docker/config.json:ro')
        ->toContain("docker buildx imagetools inspect 'docker.io/coollabsio/coolify:next'")
        ->toContain("--format '{{json .Image}}'")
        ->toContain("--arg platform 'linux/arm64'")
        ->toContain('jq')
        ->not->toContain('docker pull')
        ->and(strpos($command, 'coolify-helper:1.0.17'))
        ->toBeLessThan(strpos($command, 'docker buildx imagetools inspect'));
});

it('accepts only versions produced by the rolling next workflow', function () {
    expect(CoolifyUpdateTargetResolver::isRollingBuildVersion('4.5-rc.1.abc1234'))->toBeTrue()
        ->and(CoolifyUpdateTargetResolver::isRollingBuildVersion('4.5-rc.1.abc123'))->toBeFalse()
        ->and(CoolifyUpdateTargetResolver::isRollingBuildVersion('4.5.1'))->toBeFalse();
});

it('fails instead of returning a stable version when the remote target is invalid', function () {
    $resolver = new CoolifyUpdateTargetResolver(
        fn (array $commands, Server $server): string => '4.5.1'
    );

    expect(fn () => $resolver->resolve(new Server([
        'server_metadata' => ['arch' => 'x86_64'],
    ])))->toThrow(RuntimeException::class, 'valid COOLIFY_VERSION');
});
