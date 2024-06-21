<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Visus\Cuid2\Cuid2;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StandaloneDocker>
 */
class StandaloneDockerFactory extends Factory
{
    public function definition()
    {
        return [
            'name' => 'standalone-docker-'.(string) new Cuid2(7),
            'uuid' => (string) new Cuid2(7),
            'network' => 'coolify',
            'server_id' => Server::factory(),
        ];
    }
}
