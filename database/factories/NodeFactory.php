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
            // SSH keys are looked up within the node team, and a team cannot store the same key twice.
            'private_key_id' => fn (array $attributes) => PrivateKey::query()->where('team_id', $attributes['team_id'])->value('id')
                ?? PrivateKey::factory()->create(['team_id' => $attributes['team_id']])->id,
            'name' => fake()->unique()->name(),
            'role' => NodeRole::WORKER,
            'ip' => fake()->unique()->ipv4(),
            'port' => 22,
            'user' => 'root',
        ];
    }
}
