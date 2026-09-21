<?php

use App\Jobs\CheckForUpdatesJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Services\CoolifyUpdateTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function checkForUpdatesRollingCreateRootServer(): void
{
    Team::forceCreate(['id' => 0, 'name' => 'root']);
    Server::forceCreate([
        'id' => 0,
        'name' => 'localhost',
        'ip' => '127.0.0.1',
        'user' => 'root',
        'team_id' => 0,
        'private_key_id' => 1,
    ]);
    InstanceSettings::forceCreate([
        'id' => 0,
        'new_version_available' => false,
    ]);
}

it('uses the published rolling target identity instead of the stable manifest version', function () {
    checkForUpdatesRollingCreateRootServer();
    config([
        'constants.coolify.latest_image' => 'next',
        'constants.coolify.version' => '4.5-rc.1.def5678',
    ]);
    Http::fake(['*' => Http::response([
        'coolify' => ['v4' => ['version' => '4.5.1']],
        'traefik' => ['v3.5' => '3.5.6'],
    ])]);
    Queue::fake();

    File::shouldReceive('put')
        ->once()
        ->with(base_path('versions.json'), Mockery::on(function (string $json): bool {
            $versions = json_decode($json, true);

            return data_get($versions, 'coolify.v4.version') === '4.5-rc.1.abc1234'
                && data_get($versions, 'traefik.v3.5') === '3.5.6';
        }))
        ->andReturn(true);
    Cache::shouldReceive('forget')->once();

    $this->app->instance(CoolifyUpdateTargetResolver::class, new CoolifyUpdateTargetResolver(
        fn (array $commands, Server $server): string => '4.5-rc.1.abc1234'
    ));

    (new CheckForUpdatesJob)->handle();

    expect((bool) InstanceSettings::findOrFail(0)->new_version_available)->toBeTrue();
    Queue::assertPushed(\App\Jobs\CheckTraefikVersionJob::class);
});

it('does not replace a failed rolling resolution with the stable manifest version', function () {
    checkForUpdatesRollingCreateRootServer();
    config([
        'constants.coolify.latest_image' => 'next',
        'constants.coolify.version' => '4.5-rc.1.abc1234',
    ]);
    Http::fake(['*' => Http::response([
        'coolify' => ['v4' => ['version' => '4.5.1']],
    ])]);
    Queue::fake();
    File::shouldReceive('put')->never();
    Cache::shouldReceive('forget')->never();
    Log::shouldReceive('warning')
        ->once()
        ->with('Failed to resolve the rolling Coolify update target', Mockery::type('array'));

    $this->app->instance(CoolifyUpdateTargetResolver::class, new CoolifyUpdateTargetResolver(
        fn (array $commands, Server $server): string => '4.5.1'
    ));

    (new CheckForUpdatesJob)->handle();

    expect((bool) InstanceSettings::findOrFail(0)->new_version_available)->toBeFalse();
});
