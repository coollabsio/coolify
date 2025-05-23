<?php

namespace Tests\Unit\Jobs;

use App\Actions\Server\CollectServerInfo;
use App\Events\ServerInfoUpdated;
use App\Jobs\CollectServerInfoJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CollectServerInfoJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_calls_collect_server_info_action_and_dispatches_event()
    {
        // Create instance settings
        InstanceSettings::factory()->create();

        // Mock the CollectServerInfo action
        $this->mock(CollectServerInfo::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andReturn(true);
        });

        // Set up event fake
        Event::fake([ServerInfoUpdated::class]);

        // Create a team
        $team = Team::factory()->create();

        // Create a server with settings
        $server = Server::factory()->create([
            'team_id' => $team->id,
        ]);

        // Create server settings
        ServerSetting::factory()->create([
            'server_id' => $server->id,
        ]);

        // Dispatch the job
        CollectServerInfoJob::dispatch($server);

        // Assert that the event was dispatched
        Event::assertDispatched(ServerInfoUpdated::class, function ($event) use ($server) {
            return $event->server->id === $server->id;
        });
    }

    public function test_job_handles_exceptions_gracefully()
    {
        // Create instance settings
        InstanceSettings::factory()->create();

        // Mock the CollectServerInfo action to throw an exception
        $this->mock(CollectServerInfo::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andThrow(new \Exception('Test exception'));
        });

        // Set up event fake
        Event::fake([ServerInfoUpdated::class]);

        // Create a team
        $team = Team::factory()->create();

        // Create a server with settings
        $server = Server::factory()->create([
            'team_id' => $team->id,
        ]);

        // Create server settings
        ServerSetting::factory()->create([
            'server_id' => $server->id,
        ]);

        // Dispatch the job
        CollectServerInfoJob::dispatch($server);

        // Assert that the event was not dispatched
        Event::assertNotDispatched(ServerInfoUpdated::class);
    }
}
