<?php

namespace Database\Factories;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NodeOperation> */
class NodeOperationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'command_type' => 'workload.deploy.v1',
            'idempotency_key' => fake()->uuid(),
            'status' => NodeOperationStatus::QUEUED,
            'request' => [],
        ];
    }
}
