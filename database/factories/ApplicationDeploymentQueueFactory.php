<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Factories\Factory;
use Visus\Cuid2\Cuid2;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ApplicationDeploymentQueue>
 */
class ApplicationDeploymentQueueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $server = Server::factory()->create();

        $destination = StandaloneDocker::factory()->create([
            'server_id' => $server->id,
        ]);

        return [
            'application_id' => Application::factory(),
            'deployment_uuid' => (string) new Cuid2(7),
            'commit' => '81024772fb19308dd49c21ac7968cc340b1a0784',
            'pull_request_id' => 0,
            'server_id' => $server->id,
            'destination_id' => $destination->id,
        ];
    }
}
