<?php

namespace App\Actions\Kubernetes;

use App\Models\Application;
use App\Models\KubernetesCluster;
use App\Services\Kubernetes\KubernetesApplicationManifestGenerator;
use App\Services\Kubernetes\KubernetesApplicationStatusResolver;
use App\Services\Kubernetes\KubernetesKubectlCommandBuilder;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class GetKubernetesApplicationStatus
{
    use AsAction;

    public function handle(Application $application): ?string
    {
        $cluster = $application->destination;

        if (! ($cluster instanceof KubernetesCluster)) {
            return null;
        }

        if (! $cluster->server->isFunctional()) {
            $application->update(['status' => 'exited']);

            return 'exited';
        }

        $builder = new KubernetesKubectlCommandBuilder;
        $resourceName = (new KubernetesApplicationManifestGenerator)->resourceName($application);
        [$commands, $kubeconfigPath] = $this->commandContext($cluster, $builder);

        $deploymentJson = instant_remote_process([
            ...$commands,
            $builder->getDeployment($cluster, $resourceName, $kubeconfigPath),
        ], $cluster->server);
        $podsJson = instant_remote_process([
            $builder->getPods($cluster, $this->podSelector($resourceName), $kubeconfigPath),
        ], $cluster->server);

        $status = (new KubernetesApplicationStatusResolver)->resolve($deploymentJson ?: '{}', $podsJson ?: '{"items":[]}');
        $updates = ['status' => $status];

        if (str($status)->startsWith('running')) {
            $updates['last_online_at'] = now();
        }

        $application->update($updates);

        return $status;
    }

    private function commandContext(KubernetesCluster $cluster, KubernetesKubectlCommandBuilder $builder): array
    {
        $commands = ['mkdir -p '.escapeshellarg($cluster->configurationDirectory())];
        $kubeconfigPath = $cluster->effectiveKubeconfigPath();

        if (filled($cluster->kubeconfig)) {
            $commands[] = $builder->writeKubeconfig($cluster->storedKubeconfigPath(), $cluster->kubeconfig);
            $kubeconfigPath = $cluster->storedKubeconfigPath();
        }

        if (blank($kubeconfigPath)) {
            throw new RuntimeException('Kubernetes kubeconfig is not configured.');
        }

        return [$commands, $kubeconfigPath];
    }

    private function podSelector(string $resourceName): string
    {
        return "app.kubernetes.io/name={$resourceName},".KubernetesKubectlCommandBuilder::COOLIFY_POD_SELECTOR;
    }
}
