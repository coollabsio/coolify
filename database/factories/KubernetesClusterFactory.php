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
            'create_namespace' => false,
            'context' => null,
            'kubeconfig_path' => null,
            'kubeconfig' => null,
            'ingress_class' => 'traefik',
            'ingress_tls_secret' => null,
            'ingress_annotations' => null,
            'service_type' => 'ClusterIP',
            'service_account_name' => null,
            'create_service_account' => false,
            'image_pull_secrets' => null,
            'storage_class' => null,
            'storage_size' => '1Gi',
            'replicas' => 1,
            'autoscaling_enabled' => false,
            'min_replicas' => 1,
            'max_replicas' => 3,
            'target_cpu_utilization_percentage' => 70,
            'node_selector' => null,
            'tolerations' => null,
            'pod_disruption_budget_enabled' => false,
            'pod_disruption_budget_min_available' => null,
            'server_id' => 1,
        ];
    }
}
