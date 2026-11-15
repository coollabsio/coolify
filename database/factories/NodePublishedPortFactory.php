<?php

namespace Database\Factories;

use App\Models\NodeCluster;
use App\Models\NodePublishedPort;
use App\Models\NodeWorkload;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NodePublishedPort> */
class NodePublishedPortFactory extends Factory
{
    protected $model = NodePublishedPort::class;

    public function definition(): array
    {
        $port = fake()->unique()->numberBetween(10000, 60000);

        return [
            'node_cluster_id' => NodeCluster::factory(),
            'node_workload_id' => NodeWorkload::factory(),
            'protocol' => 'tcp',
            'host_port' => $port,
            'container_port' => $port,
            'source_cidrs' => null,
        ];
    }
}
