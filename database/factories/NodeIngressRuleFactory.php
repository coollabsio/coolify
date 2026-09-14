<?php

namespace Database\Factories;

use App\Models\NodeCluster;
use App\Models\NodeIngressRule;
use App\Models\NodeWorkload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodeIngressRule>
 */
class NodeIngressRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'node_cluster_id' => NodeCluster::factory(),
            'destination_workload_id' => NodeWorkload::factory(),
            'protocol' => 'tcp',
            'port' => fake()->numberBetween(1, 65535),
        ];
    }
}
