<?php

namespace App\Services\Kubernetes;

use App\Models\KubernetesCluster;

class KubernetesKubectlCommandBuilder
{
    public const COOLIFY_POD_SELECTOR = 'app.kubernetes.io/managed-by=coolify';

    public function version(KubernetesCluster $cluster, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath).' version --output=yaml';
    }

    public function apply(KubernetesCluster $cluster, string $manifestPath, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath).' apply -f '.escapeshellarg($manifestPath);
    }

    public function serverSideDryRun(KubernetesCluster $cluster, string $manifestPath, ?string $kubeconfigPath = null): string
    {
        return $this->apply($cluster, $manifestPath, $kubeconfigPath).' --dry-run=server';
    }

    public function ensureNamespace(KubernetesCluster $cluster, ?string $kubeconfigPath = null): string
    {
        $namespace = escapeshellarg($cluster->namespace);

        return $this->base($cluster, $kubeconfigPath).' create namespace '.$namespace.' --dry-run=client -o yaml | '.$this->base($cluster, $kubeconfigPath).' apply -f -';
    }

    public function rolloutStatus(KubernetesCluster $cluster, string $deploymentName, int $timeoutSeconds = 300, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath).' rollout status '.escapeshellarg("deployment/{$deploymentName}").' --timeout='.((int) $timeoutSeconds).'s';
    }

    public function rolloutRestart(KubernetesCluster $cluster, string $deploymentName, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath).' rollout restart '.escapeshellarg("deployment/{$deploymentName}");
    }

    public function scaleDeployment(KubernetesCluster $cluster, string $deploymentName, int $replicas, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath).' scale '.escapeshellarg("deployment/{$deploymentName}").' --replicas='.((int) $replicas);
    }

    public function getPods(KubernetesCluster $cluster, string $selector = self::COOLIFY_POD_SELECTOR, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath).' get pods --selector='.escapeshellarg($selector).' -o json';
    }

    public function getResources(KubernetesCluster $cluster, string $selector = self::COOLIFY_POD_SELECTOR, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath)
            .' get deployment,service,ingress,hpa,pdb,pvc,secret,serviceaccount'
            .' --selector='.escapeshellarg($selector)
            .' -o json';
    }

    public function getDeployment(KubernetesCluster $cluster, string $deploymentName, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath).' get '.escapeshellarg("deployment/{$deploymentName}").' -o json';
    }

    public function podLogs(KubernetesCluster $cluster, string $podName, ?string $containerName = null, int $tail = 200, ?string $kubeconfigPath = null): string
    {
        $command = $this->base($cluster, $kubeconfigPath).' logs '.escapeshellarg("pod/{$podName}").' --tail='.((int) $tail);

        if (filled($containerName)) {
            $command .= ' --container='.escapeshellarg($containerName);
        }

        return $command;
    }

    public function deletePod(KubernetesCluster $cluster, string $podName, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath).' delete '.escapeshellarg("pod/{$podName}").' --ignore-not-found=true';
    }

    public function deleteApplicationResources(KubernetesCluster $cluster, string $applicationUuid, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath)
            .' delete deployment,service,ingress,hpa,pdb,secret,serviceaccount'
            .' --selector='.escapeshellarg($this->applicationSelector($applicationUuid))
            .' --ignore-not-found=true';
    }

    public function deleteApplicationPersistentVolumeClaims(KubernetesCluster $cluster, string $applicationUuid, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath)
            .' delete pvc'
            .' --selector='.escapeshellarg($this->applicationSelector($applicationUuid))
            .' --ignore-not-found=true';
    }

    public function deleteServiceResources(KubernetesCluster $cluster, string $serviceUuid, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath)
            .' delete deployment,service,ingress,hpa,pdb,secret,serviceaccount'
            .' --selector='.escapeshellarg($this->serviceSelector($serviceUuid))
            .' --ignore-not-found=true';
    }

    public function deleteServicePersistentVolumeClaims(KubernetesCluster $cluster, string $serviceUuid, ?string $kubeconfigPath = null): string
    {
        return $this->base($cluster, $kubeconfigPath)
            .' delete pvc'
            .' --selector='.escapeshellarg($this->serviceSelector($serviceUuid))
            .' --ignore-not-found=true';
    }

    public function writeManifest(string $manifestPath, string $manifestYaml): string
    {
        return $this->writeFile($manifestPath, $manifestYaml);
    }

    public function writeKubeconfig(string $kubeconfigPath, string $kubeconfig): string
    {
        return $this->writeFile($kubeconfigPath, $kubeconfig).' && chmod 600 '.escapeshellarg($kubeconfigPath);
    }

    public function writeFile(string $path, string $contents): string
    {
        return 'printf %s '.escapeshellarg(base64_encode($contents)).' | base64 -d > '.escapeshellarg($path);
    }

    private function base(KubernetesCluster $cluster, ?string $kubeconfigPath = null): string
    {
        $command = 'kubectl';

        $effectiveKubeconfigPath = $kubeconfigPath ?: $cluster->effectiveKubeconfigPath();

        if ($effectiveKubeconfigPath) {
            $command .= ' --kubeconfig='.escapeshellarg($effectiveKubeconfigPath);
        }

        if ($cluster->context) {
            $command .= ' --context='.escapeshellarg($cluster->context);
        }

        if ($cluster->namespace) {
            $command .= ' --namespace='.escapeshellarg($cluster->namespace);
        }

        return $command;
    }

    private function applicationSelector(string $applicationUuid): string
    {
        return 'app.kubernetes.io/managed-by=coolify,coolify.io/application-uuid='.$applicationUuid;
    }

    private function serviceSelector(string $serviceUuid): string
    {
        return 'app.kubernetes.io/managed-by=coolify,coolify.io/service-uuid='.$serviceUuid;
    }
}
