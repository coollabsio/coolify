<?php

namespace Database\Factories;

use App\Models\NodeWorkload;
use Illuminate\Database\Eloquent\Factories\Factory;

class NodeWorkloadRevisionFactory extends Factory
{
    public function definition(): array
    {
        $configuration = ['image' => fake()->word().':latest'];

        return [
            'node_workload_id' => NodeWorkload::factory(),
            'configuration_hash' => hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)),
            'image' => $configuration['image'],
            'configuration' => $configuration,
        ];
    }
}
