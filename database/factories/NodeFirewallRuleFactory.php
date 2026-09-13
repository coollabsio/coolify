<?php

namespace Database\Factories;

use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\NodeWorkload;
use Illuminate\Database\Eloquent\Factories\Factory;

class NodeFirewallRuleFactory extends Factory
{
    protected $model = NodeFirewallRule::class;

    public function definition(): array
    {
        return [
            'node_cluster_id' => NodeCluster::factory(),
            'source_workload_id' => NodeWorkload::factory(),
            'destination_workload_id' => NodeWorkload::factory(),
            'protocol' => 'tcp',
            'port' => fake()->numberBetween(1, 65535),
        ];
    }
}
