<?php

namespace Database\Factories;

use App\Enums\NodeContainerManagementState;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class NodeContainerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'runtime_id' => Str::random(64),
            'name' => fake()->slug(2),
            'image' => 'docker.io/library/alpine:latest',
            'state' => 'running',
            'labels' => [],
            'management_state' => NodeContainerManagementState::EXTERNAL,
            'is_managed' => false,
            'observed_at' => now(),
        ];
    }
}
