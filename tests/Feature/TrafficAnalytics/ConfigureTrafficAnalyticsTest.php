<?php

use App\Actions\Proxy\GetProxyConfiguration;
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

it('enables analytics, regenerates proxy config and recreates sentinel', function () {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->atLeast()->once();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->atLeast()->once();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    ConfigureTrafficAnalytics::run($server, true);

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeTrue();
    Queue::assertPushed(RestartProxyJob::class);
});

it('disables analytics', function () {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->atLeast()->once();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->atLeast()->once();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    ConfigureTrafficAnalytics::run($server, true);
    ConfigureTrafficAnalytics::run($server, false);

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();
});
