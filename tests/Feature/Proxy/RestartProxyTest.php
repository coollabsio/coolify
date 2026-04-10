<?php

namespace Tests\Feature\Proxy;

use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RestartProxyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Team $team;

    protected Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user and team
        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['name' => 'Test Team']);
        $this->user->teams()->attach($this->team);

        // Create test server
        $this->server = Server::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Server',
            'ip' => '192.168.1.100',
        ]);

        // Authenticate user
        $this->actingAs($this->user);
    }

    public function test_restart_dispatches_job_for_all_servers()
    {
        Queue::fake();

        Livewire::test('server.navbar', ['server' => $this->server])
            ->call('restart');

        // Assert job was dispatched
        Queue::assertPushed(RestartProxyJob::class, function ($job) {
            return $job->server->id === $this->server->id;
        });
    }

    public function test_restart_dispatches_job_for_localhost_server()
    {
        Queue::fake();

        // Create localhost server (id = 0)
        $localhostServer = Server::factory()->create([
            'id' => 0,
            'team_id' => $this->team->id,
            'name' => 'Localhost',
            'ip' => 'host.docker.internal',
        ]);

        Livewire::test('server.navbar', ['server' => $localhostServer])
            ->call('restart');

        // Assert job was dispatched
        Queue::assertPushed(RestartProxyJob::class, function ($job) use ($localhostServer) {
            return $job->server->id === $localhostServer->id;
        });
    }

    public function test_restart_shows_info_message()
    {
        Queue::fake();

        Livewire::test('server.navbar', ['server' => $this->server])
            ->call('restart')
            ->assertDispatched('info', 'Proxy restart initiated. Monitor progress in activity logs.');
    }

    public function test_unauthorized_user_cannot_restart_proxy()
    {
        Queue::fake();

        // Create another user without access
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);

        Livewire::test('server.navbar', ['server' => $this->server])
            ->call('restart')
            ->assertForbidden();

        // Assert job was NOT dispatched
        Queue::assertNotPushed(RestartProxyJob::class);
    }

    public function test_restart_prevents_concurrent_jobs_via_without_overlapping()
    {
        Queue::fake();

        // Dispatch job twice
        Livewire::test('server.navbar', ['server' => $this->server])
            ->call('restart');

        Livewire::test('server.navbar', ['server' => $this->server])
            ->call('restart');

        // Assert job was pushed twice (WithoutOverlapping middleware will handle deduplication)
        Queue::assertPushed(RestartProxyJob::class, 2);

        // Get the jobs
        $jobs = Queue::pushed(RestartProxyJob::class);

        // Verify both jobs have WithoutOverlapping middleware
        foreach ($jobs as $job) {
            $middleware = $job['job']->middleware();
            $this->assertCount(1, $middleware);
            $this->assertInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class, $middleware[0]);
        }
    }

    public function test_restart_uses_server_team_id()
    {
        Queue::fake();

        Livewire::test('server.navbar', ['server' => $this->server])
            ->call('restart');

        Queue::assertPushed(RestartProxyJob::class, function ($job) {
            return $job->server->team_id === $this->team->id;
        });
    }
}
