<?php

namespace Tests\Unit\Jobs;

use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
use App\Events\ProxyStatusChangedUI;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Event;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RestartProxyJobTest extends TestCase
{
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
        $server->shouldReceive('getAttribute')->with('proxy')->andReturn((object) ['force_stop' => true]);
        $server->shouldReceive('save')->once();

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

        // Execute job
        $job = new RestartProxyJob($server);
        $job->handle();

        // Assert activity ID was set
        $this->assertEquals(123, $job->activity_id);
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
            return $event->teamId === 1;
        });
    }

    public function test_job_clears_force_stop_flag()
    {
        // Mock Server
        $proxy = (object) ['force_stop' => true];
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('proxy')->andReturn($proxy);
        $server->shouldReceive('save')->once();

        // Mock Activity
        $activity = Mockery::mock(Activity::class);
        $activity->id = 123;

        // Mock Actions
        Mockery::mock('alias:'.StopProxy::class)
            ->shouldReceive('run')->once();

        Mockery::mock('alias:'.StartProxy::class)
            ->shouldReceive('run')->once()->andReturn($activity);

        // Execute job
        $job = new RestartProxyJob($server);
        $job->handle();

        // Assert force_stop was set to false
        $this->assertFalse($proxy->force_stop);
    }

    public function test_job_stores_activity_id_when_activity_returned()
    {
        // Mock Server
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('proxy')->andReturn((object) ['force_stop' => true]);
        $server->shouldReceive('save')->once();

        // Mock Activity
        $activity = Mockery::mock(Activity::class);
        $activity->id = 456;

        // Mock Actions
        Mockery::mock('alias:'.StopProxy::class)
            ->shouldReceive('run')->once();

        Mockery::mock('alias:'.StartProxy::class)
            ->shouldReceive('run')->once()->andReturn($activity);

        // Execute job
        $job = new RestartProxyJob($server);
        $job->handle();

        // Assert activity ID was stored
        $this->assertEquals(456, $job->activity_id);
    }

    public function test_job_handles_string_return_from_start_proxy()
    {
        // Mock Server
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('proxy')->andReturn((object) ['force_stop' => true]);
        $server->shouldReceive('save')->once();

        // Mock Actions - StartProxy returns 'OK' string when proxy is disabled
        Mockery::mock('alias:'.StopProxy::class)
            ->shouldReceive('run')->once();

        Mockery::mock('alias:'.StartProxy::class)
            ->shouldReceive('run')->once()->andReturn('OK');

        // Execute job
        $job = new RestartProxyJob($server);
        $job->handle();

        // Assert activity ID remains null when string returned
        $this->assertNull($job->activity_id);
    }
}
