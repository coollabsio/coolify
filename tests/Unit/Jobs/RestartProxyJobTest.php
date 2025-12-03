<?php

namespace Tests\Unit\Jobs;

use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChangedUI;
use App\Jobs\CheckTraefikVersionForServerJob;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RestartProxyJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_job_has_without_overlapping_middleware()
    {
        $server = Mockery::mock(Server::class);
        $server->uuid = 'test-uuid';

        $job = new RestartProxyJob($server);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    public function test_job_stops_and_starts_proxy()
    {
        // Mock Server
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('team_id')->andReturn(1);
        $server->shouldReceive('getAttribute')->with('proxy')->andReturn((object) ['force_stop' => true]);
        $server->shouldReceive('save')->once();
        $server->shouldReceive('proxyType')->andReturn(ProxyTypes::TRAEFIK->value);
        $server->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $server->shouldReceive('getAttribute')->with('name')->andReturn('test-server');

        // Mock Activity
        $activity = Mockery::mock(Activity::class);
        $activity->id = 123;

        // Mock Actions
        $stopProxyMock = Mockery::mock('alias:'.StopProxy::class);
        $stopProxyMock->shouldReceive('run')
            ->once()
            ->with($server, restarting: true);

        $startProxyMock = Mockery::mock('alias:'.StartProxy::class);
        $startProxyMock->shouldReceive('run')
            ->once()
            ->with($server, force: true, restarting: true)
            ->andReturn($activity);

        // Mock Events
        Event::fake();
        Queue::fake();

        // Mock get_traefik_versions helper
        $this->app->instance('traefik_versions', ['latest' => '2.10']);

        // Execute job
        $job = new RestartProxyJob($server);
        $job->handle();

        // Assert activity ID was set
        $this->assertEquals(123, $job->activity_id);

        // Assert event was dispatched
        Event::assertDispatched(ProxyStatusChangedUI::class, function ($event) {
            return $event->teamId === 1 && $event->activityId === 123;
        });

        // Assert Traefik version check was dispatched
        Queue::assertPushed(CheckTraefikVersionForServerJob::class);
    }

    public function test_job_handles_errors_gracefully()
    {
        // Mock Server
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('team_id')->andReturn(1);
        $server->shouldReceive('getAttribute')->with('proxy')->andReturn((object) ['status' => 'running']);
        $server->shouldReceive('save')->once();

        // Mock StopProxy to throw exception
        $stopProxyMock = Mockery::mock('alias:'.StopProxy::class);
        $stopProxyMock->shouldReceive('run')
            ->once()
            ->andThrow(new \Exception('Test error'));

        Event::fake();

        // Execute job
        $job = new RestartProxyJob($server);
        $job->handle();

        // Assert error event was dispatched
        Event::assertDispatched(ProxyStatusChangedUI::class, function ($event) {
            return $event->teamId === 1 && $event->activityId === null;
        });
    }

    public function test_job_skips_traefik_version_check_for_non_traefik_proxies()
    {
        // Mock Server with non-Traefik proxy
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('team_id')->andReturn(1);
        $server->shouldReceive('getAttribute')->with('proxy')->andReturn((object) ['force_stop' => true]);
        $server->shouldReceive('save')->once();
        $server->shouldReceive('proxyType')->andReturn(ProxyTypes::CADDY->value);

        // Mock Activity
        $activity = Mockery::mock(Activity::class);
        $activity->id = 123;

        // Mock Actions
        $stopProxyMock = Mockery::mock('alias:'.StopProxy::class);
        $stopProxyMock->shouldReceive('run')->once();

        $startProxyMock = Mockery::mock('alias:'.StartProxy::class);
        $startProxyMock->shouldReceive('run')->once()->andReturn($activity);

        Event::fake();
        Queue::fake();

        // Execute job
        $job = new RestartProxyJob($server);
        $job->handle();

        // Assert Traefik version check was NOT dispatched
        Queue::assertNotPushed(CheckTraefikVersionForServerJob::class);
    }

    public function test_job_clears_force_stop_flag()
    {
        // Mock Server
        $proxy = (object) ['force_stop' => true];
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('team_id')->andReturn(1);
        $server->shouldReceive('getAttribute')->with('proxy')->andReturn($proxy);
        $server->shouldReceive('save')->once();
        $server->shouldReceive('proxyType')->andReturn('NONE');

        // Mock Activity
        $activity = Mockery::mock(Activity::class);
        $activity->id = 123;

        // Mock Actions
        Mockery::mock('alias:'.StopProxy::class)
            ->shouldReceive('run')->once();

        Mockery::mock('alias:'.StartProxy::class)
            ->shouldReceive('run')->once()->andReturn($activity);

        Event::fake();

        // Execute job
        $job = new RestartProxyJob($server);
        $job->handle();

        // Assert force_stop was set to false
        $this->assertFalse($proxy->force_stop);
    }
}
