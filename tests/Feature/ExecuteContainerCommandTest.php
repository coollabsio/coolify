<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\HandlesTestDatabase;

class ExecuteContainerCommandTest extends TestCase
{
    use HandlesTestDatabase;

    private $user;

    private $team;

    private $server;

    private $application;

    protected function setUp(): void
    {
        parent::setUp();

        // Only set up database for tests that need it
        if ($this->shouldSetUpDatabase()) {
            $this->setUpTestDatabase();
        }
        // Create test data
        $this->user = User::factory()->create();
        $this->team = $this->user->teams()->first();
        $this->server = Server::factory()->create(['team_id' => $this->team->id]);
        $this->application = Application::factory()->create();

        // Login the user
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        if ($this->shouldSetUpDatabase()) {
            $this->tearDownTestDatabase();
        }
        parent::tearDown();
    }

    private function shouldSetUpDatabase(): bool
    {
        return in_array($this->name(), [
            'it_allows_valid_container_access',
            'it_prevents_cross_server_container_access',
        ]);
    }
}
