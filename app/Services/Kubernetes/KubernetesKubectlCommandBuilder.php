<?php

namespace App\Services\Kubernetes;

use App\Models\KubernetesCluster;

class KubernetesKubectlCommandBuilder
{
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
}
