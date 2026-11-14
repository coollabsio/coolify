<?php

namespace Database\Factories;

use App\Models\NodeCluster;
use App\Models\NodeStack;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NodeStack> */
class NodeStackFactory extends Factory
{
    protected $model = NodeStack::class;

    public function definition(): array
    {
        return [
            'node_cluster_id' => NodeCluster::factory(),
            'team_id' => fn (array $attributes) => NodeCluster::query()->find($attributes['node_cluster_id'])->team_id,
            'name' => fake()->unique()->word(),
            'dns_label' => 'stack-'.fake()->unique()->numberBetween(1, 99999),
            'compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
            'compose_parsed' => ['services' => ['web' => ['image' => 'nginx:alpine']]],
            'variables' => [],
        ];
    }
}
