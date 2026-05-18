<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class KubernetesClusterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'name' => fake()->unique()->word(),
            'namespace' => 'default',
            'context' => null,
            'kubeconfig_path' => null,
            'kubeconfig' => null,
            'ingress_class' => 'traefik',
            'service_type' => 'ClusterIP',
            'replicas' => 1,
            'autoscaling_enabled' => false,
            'min_replicas' => 1,
            'max_replicas' => 3,
            'target_cpu_utilization_percentage' => 70,
            'server_id' => 1,
        ];
    }
}
