<?php

namespace Database\Factories;

use App\Enums\NodeRole;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class NodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'private_key_id' => PrivateKey::factory(),
            'name' => fake()->unique()->name(),
            'role' => NodeRole::WORKER,
            'ip' => fake()->unique()->ipv4(),
            'port' => 22,
            'user' => 'root',
        ];
    }
}
