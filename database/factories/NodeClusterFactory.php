<?php

namespace Database\Factories;

use App\Models\NodeCluster;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class NodeClusterFactory extends Factory
{
    protected $model = NodeCluster::class;

    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'name' => fake()->unique()->words(2, true), 'cidr' => '10.250.'.fake()->unique()->numberBetween(0, 255).'.0/24'];
    }
}
