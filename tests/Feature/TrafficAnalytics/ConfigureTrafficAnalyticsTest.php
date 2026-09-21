<?php

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Server\ConfigureTrafficAnalytics;
use App\Actions\Server\StartSentinel;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->team = $user->teams()->first();
});

it('enables analytics without replacing custom proxy configuration', function () {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->atLeast()->once();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->once()->andReturn(<<<'YAML'
services:
  traefik:
    image: traefik:v3.7
    env_file:
      - .env
    command:
      - '--providers.docker=true'
YAML);
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->once()->withArgs(
        fn (Server $server, string $configuration): bool => str_contains($configuration, 'traefik:v3.7')
            && str_contains($configuration, 'env_file:')
            && str_contains($configuration, '--accesslog=true')
    );

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();
    ConfigureTrafficAnalytics::run($server, true);

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeTrue();
    Queue::assertPushed(RestartProxyJob::class);
});

it('disables analytics', function () {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->atLeast()->once();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->twice()->andReturn("services:\n  traefik:\n    command: []\n");
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->twice();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();
    ConfigureTrafficAnalytics::run($server, true);
    ConfigureTrafficAnalytics::run($server, false);

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();
});
