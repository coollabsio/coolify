<?php

namespace Database\Factories;

use App\Models\PrivateKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Visus\Cuid2\Cuid2;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Server>
 */
class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) new Cuid2(7),
            'name' => fake()->domainName(),
            'description' => fake()->text(),
            'ip' => 'coolify-testing-host',
            'port' => 22,
            'user' => 'root',
            'team_id' => 0,
            'private_key_id' => PrivateKey::factory(),
        ];
    }
}
