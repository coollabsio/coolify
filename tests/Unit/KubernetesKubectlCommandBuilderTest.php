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

    expect($builder->rolloutStatusStatefulSet($cluster, 'customer-db', 120))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' rollout status 'statefulset/customer-db' --timeout=120s");

    expect($builder->getPods($cluster))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' get pods --selector='app.kubernetes.io/managed-by=coolify' -o json");

    expect($builder->getResources($cluster))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' get deployment,statefulset,service,ingress,hpa,pdb,pvc,secret,serviceaccount --selector='app.kubernetes.io/managed-by=coolify' -o json");

    expect($builder->getDeployment($cluster, 'customer-api'))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' get 'deployment/customer-api' -o json");

    expect($builder->podLogs($cluster, 'customer-api-abc123', 'application', 50))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' logs 'pod/customer-api-abc123' --tail=50 --container='application'");

    expect($builder->deletePod($cluster, 'customer-api-abc123'))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' delete 'pod/customer-api-abc123' --ignore-not-found=true");

    expect($builder->deleteApplicationResources($cluster, 'app-uuid'))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' delete deployment,service,ingress,hpa,pdb,secret,serviceaccount --selector='app.kubernetes.io/managed-by=coolify,coolify.io/application-uuid=app-uuid' --ignore-not-found=true");

    expect($builder->deleteApplicationPreviewResources($cluster, 'app-uuid', 42))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' delete deployment,service,ingress,hpa,pdb,secret,serviceaccount --selector='app.kubernetes.io/managed-by=coolify,coolify.io/application-uuid=app-uuid,coolify.io/pull-request-id=42' --ignore-not-found=true");

    expect($builder->deleteApplicationPersistentVolumeClaims($cluster, 'app-uuid'))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' delete pvc --selector='app.kubernetes.io/managed-by=coolify,coolify.io/application-uuid=app-uuid' --ignore-not-found=true");

    expect($builder->deleteApplicationPreviewPersistentVolumeClaims($cluster, 'app-uuid', 42))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' delete pvc --selector='app.kubernetes.io/managed-by=coolify,coolify.io/application-uuid=app-uuid,coolify.io/pull-request-id=42' --ignore-not-found=true");

    expect($builder->deleteServiceResources($cluster, 'service-uuid'))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' delete deployment,service,ingress,hpa,pdb,secret,serviceaccount --selector='app.kubernetes.io/managed-by=coolify,coolify.io/service-uuid=service-uuid' --ignore-not-found=true");

    expect($builder->deleteServicePersistentVolumeClaims($cluster, 'service-uuid'))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' delete pvc --selector='app.kubernetes.io/managed-by=coolify,coolify.io/service-uuid=service-uuid' --ignore-not-found=true");

    expect($builder->deleteDatabaseResources($cluster, 'database-uuid'))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' delete statefulset,service,secret --selector='app.kubernetes.io/managed-by=coolify,coolify.io/database-uuid=database-uuid' --ignore-not-found=true");

    expect($builder->deleteDatabasePersistentVolumeClaims($cluster, 'database-uuid'))
        ->toBe("kubectl --kubeconfig='/data/coolify/kubernetes/iad prod.yaml' --context='iad-prod' --namespace='production' delete pvc --selector='app.kubernetes.io/managed-by=coolify,coolify.io/database-uuid=database-uuid' --ignore-not-found=true");
});

it('builds a base64 manifest write command', function () {
    $builder = new KubernetesKubectlCommandBuilder;

    expect($builder->writeManifest('/tmp/app.yaml', "kind: Service\n"))
        ->toBe("printf %s 'a2luZDogU2VydmljZQo=' | base64 -d > '/tmp/app.yaml'");
    expect($builder->writeKubeconfig('/tmp/kubeconfig', "apiVersion: v1\n"))
        ->toBe("printf %s 'YXBpVmVyc2lvbjogdjEK' | base64 -d > '/tmp/kubeconfig' && chmod 600 '/tmp/kubeconfig'");
});

it('uses the explicit kubeconfig path for mutable kubernetes commands', function () {
    $cluster = new KubernetesCluster([
        'namespace' => 'production',
        'context' => 'iad-prod',
        'kubeconfig_path' => '/old/kubeconfig',
    ]);

    $builder = new KubernetesKubectlCommandBuilder;
    $kubeconfigPath = '/run/coolify/kubeconfig';

    expect($builder->rolloutRestart($cluster, 'customer-api', $kubeconfigPath))
        ->toBe("kubectl --kubeconfig='/run/coolify/kubeconfig' --context='iad-prod' --namespace='production' rollout restart 'deployment/customer-api'");

    expect($builder->scaleDeployment($cluster, 'customer-api', 0, $kubeconfigPath))
        ->toBe("kubectl --kubeconfig='/run/coolify/kubeconfig' --context='iad-prod' --namespace='production' scale 'deployment/customer-api' --replicas=0");

    expect($builder->scaleStatefulSet($cluster, 'customer-db', 0, $kubeconfigPath))
        ->toBe("kubectl --kubeconfig='/run/coolify/kubeconfig' --context='iad-prod' --namespace='production' scale 'statefulset/customer-db' --replicas=0");
});

it('escapes shell metacharacters in kubernetes command arguments', function () {
    $cluster = new KubernetesCluster([
        'namespace' => 'prod;cat /etc/passwd',
        'context' => 'ctx`whoami`',
        'kubeconfig_path' => '/tmp/kubeconfig $(id)',
    ]);

    $builder = new KubernetesKubectlCommandBuilder;

    expect($builder->deletePod($cluster, 'api;curl attacker'))
        ->toBe("kubectl --kubeconfig='/tmp/kubeconfig $(id)' --context='ctx`whoami`' --namespace='prod;cat /etc/passwd' delete 'pod/api;curl attacker' --ignore-not-found=true");
});
