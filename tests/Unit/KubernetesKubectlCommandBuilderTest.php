<?php

use App\Models\KubernetesCluster;
use App\Services\Kubernetes\KubernetesKubectlCommandBuilder;

it('builds escaped kubectl commands for a cluster destination', function () {
    $cluster = new KubernetesCluster([
        'namespace' => 'production',
        'context' => 'iad-prod',
        'kubeconfig_path' => '/data/coolify/kubernetes/iad prod.yaml',
    ]);

    $builder = new KubernetesKubectlCommandBuilder;

    expect($builder->serverSideDryRun($cluster, '/data/coolify/app manifests/api.yaml'))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' apply -f '/data/coolify/app manifests/api.yaml' --dry-run=server");

    expect($builder->ensureNamespace($cluster))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' create namespace 'production' --dry-run=client -o yaml | kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' apply -f -");

    expect($builder->rolloutStatus($cluster, 'customer-api', 120))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' rollout status 'deployment/customer-api' --timeout=120s");

    expect($builder->getPods($cluster))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' get pods --selector='app.kubernetes.io/managed-by=coolify' -o json");

    expect($builder->getDeployment($cluster, 'customer-api'))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' get 'deployment/customer-api' -o json");

    expect($builder->podLogs($cluster, 'customer-api-abc123', 'application', 50))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' logs 'pod/customer-api-abc123' --tail=50 --container='application'");

    expect($builder->deletePod($cluster, 'customer-api-abc123'))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' delete 'pod/customer-api-abc123' --ignore-not-found=true");
});

it('builds a base64 manifest write command', function () {
    $builder = new KubernetesKubectlCommandBuilder;

    expect($builder->writeManifest('/tmp/app.yaml', "kind: Service\n"))
        ->toBe("printf %s 'a2luZDogU2VydmljZQo=' | base64 -d > '/tmp/app.yaml'");
    expect($builder->writeKubeconfig('/tmp/kubeconfig', "apiVersion: v1\n"))
        ->toBe("printf %s 'YXBpVmVyc2lvbjogdjEK' | base64 -d > '/tmp/kubeconfig' && chmod 600 '/tmp/kubeconfig'");
});
