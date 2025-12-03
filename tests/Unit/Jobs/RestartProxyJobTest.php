<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RestartProxyJob;
use App\Models\Server;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for RestartProxyJob.
 *
 * These tests focus on testing the job's middleware configuration and constructor.
 * Full integration tests for the job's handle() method are in tests/Feature/Proxy/
 * because they require database and complex mocking of SchemalessAttributes.
 */
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
        $server->shouldReceive('getSchemalessAttributes')->andReturn([]);
        $server->shouldReceive('getAttribute')->with('uuid')->andReturn('test-uuid');

        $job = new RestartProxyJob($server);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    public function test_job_has_correct_configuration()
    {
        $server = Mockery::mock(Server::class);

        $job = new RestartProxyJob($server);

        $this->assertEquals(1, $job->tries);
        $this->assertEquals(120, $job->timeout);
        $this->assertNull($job->activity_id);
    }

    public function test_job_stores_server()
    {
        $server = Mockery::mock(Server::class);

        $job = new RestartProxyJob($server);

        $this->assertSame($server, $job->server);
    }
}
