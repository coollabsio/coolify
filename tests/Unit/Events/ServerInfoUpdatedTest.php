<?php

namespace Tests\Unit\Events;

use App\Events\ServerInfoUpdated;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerInfoUpdatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_broadcasts_on_correct_channel()
    {
        // Create a team
        $team = Team::factory()->create();

        // Create a server
        $server = Server::factory()->create([
            'team_id' => $team->id,
        ]);

        // Create the event
        $event = new ServerInfoUpdated($server);

        // Assert that the event broadcasts on the correct channel
        $this->assertEquals(['private-team.' . $team->id], $event->broadcastOn());
    }

    public function test_event_broadcasts_with_correct_name()
    {
        // Create a team
        $team = Team::factory()->create();

        // Create a server
        $server = Server::factory()->create([
            'team_id' => $team->id,
        ]);

        // Create the event
        $event = new ServerInfoUpdated($server);

        // Assert that the event broadcasts with the correct name
        $this->assertEquals('ServerInfoUpdated', $event->broadcastAs());
    }

    public function test_event_broadcasts_with_correct_data()
    {
        // Create a team
        $team = Team::factory()->create();

        // Create a server
        $server = Server::factory()->create([
            'team_id' => $team->id,
        ]);

        // Create the event
        $event = new ServerInfoUpdated($server);

        // Assert that the event broadcasts with the correct data
        $this->assertEquals(['server_uuid' => $server->uuid], $event->broadcastWith());
    }
}
