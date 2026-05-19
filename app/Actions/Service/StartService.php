<?php

namespace App\Actions\Service;

use App\Events\ServiceStatusChanged;
use App\Models\KubernetesCluster;
use App\Models\Service;
use App\Services\Kubernetes\KubernetesKubectlCommandBuilder;
use App\Services\Kubernetes\KubernetesServiceManifestGenerator;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartService
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Service $service, bool $pullLatestImages = false, bool $stopBeforeStart = false)
    {
        $service->parse();
        if ($service->destination instanceof KubernetesCluster) {
            return $this->startKubernetesService($service, $stopBeforeStart);
        }

        if ($stopBeforeStart) {
            StopService::run(service: $service, dockerCleanup: false);
        }
        $service->saveComposeConfigs();
        $service->isConfigurationChanged(save: true);
        $workdir = $service->workdir();
        // $commands[] = "cd {$workdir}";
        $commands[] = "echo 'Saved configuration files to {$workdir}.'";
        // Ensure .env exists in the correct directory before docker compose tries to load it
        // This is defensive programming - saveComposeConfigs() already creates it,
        // but we guarantee it here in case of any edge cases or manual deployments
        $commands[] = "touch {$workdir}/.env";
        if ($pullLatestImages) {
            $commands[] = "echo 'Pulling images.'";
            $commands[] = "docker compose --project-directory {$workdir} pull";
        }
        if ($service->networks()->count() > 0) {
            $commands[] = "echo 'Creating Docker network.'";
            $commands[] = "docker network inspect $service->uuid >/dev/null 2>&1 || docker network create --attachable $service->uuid";
        }
        $commands[] = 'echo Starting service.';
        $commands[] = "docker compose --project-directory {$workdir} -f {$workdir}/docker-compose.yml --project-name {$service->uuid} up -d --remove-orphans --force-recreate --build";
        $commands[] = "docker network connect $service->uuid coolify-proxy >/dev/null 2>&1 || true";
        if (data_get($service, 'connect_to_docker_network')) {
            $compose = data_get($service, 'docker_compose', []);
            $safeNetwork = escapeshellarg($service->destination->network);
            $serviceNames = data_get(Yaml::parse($compose), 'services', []);
            foreach ($serviceNames as $serviceName => $serviceConfig) {
                $commands[] = "docker network connect --alias {$serviceName}-{$service->uuid} {$safeNetwork} {$serviceName}-{$service->uuid} >/dev/null 2>&1 || true";
            }
        }

        return remote_process($commands, $service->server, type_uuid: $service->uuid, callEventOnFinish: 'ServiceStatusChanged');
    }

    private function startKubernetesService(Service $service, bool $stopBeforeStart): void
    {
        if ($stopBeforeStart) {
            StopService::run(service: $service, dockerCleanup: false);
        }

        $service->isConfigurationChanged(save: true);
        $cluster = $service->destination;
        $builder = new KubernetesKubectlCommandBuilder;
        $generator = new KubernetesServiceManifestGenerator;
        $compose = Yaml::parse($service->docker_compose ?: $service->docker_compose_raw) ?: [];
        $manifestDirectory = "{$service->workdir()}/kubernetes";
        $manifestPath = "{$manifestDirectory}/manifest.yaml";
        $kubeconfigPath = $cluster->effectiveKubeconfigPath();
        $commands = [
            'mkdir -p '.escapeshellarg($manifestDirectory),
            'mkdir -p '.escapeshellarg($cluster->configurationDirectory()),
        ];

        if (filled($cluster->kubeconfig)) {
            $commands[] = $builder->writeKubeconfig($cluster->storedKubeconfigPath(), $cluster->kubeconfig);
            $kubeconfigPath = $cluster->storedKubeconfigPath();
        }

        if (blank($kubeconfigPath)) {
            throw new \RuntimeException('Kubernetes kubeconfig is not configured.');
        }

        $manifestYaml = $generator->toYaml($service, $compose, $this->manifestOptions($cluster));
        $commands[] = $builder->writeManifest($manifestPath, $manifestYaml);

        if ($cluster->create_namespace) {
            $commands[] = $builder->ensureNamespace($cluster, $kubeconfigPath);
        }

        $deploymentNames = $generator->resourceNames($service, $compose);
        $commands[] = $builder->serverSideDryRun($cluster, $manifestPath, $kubeconfigPath);
        $commands[] = $builder->apply($cluster, $manifestPath, $kubeconfigPath);

        foreach ($deploymentNames as $deploymentName) {
            $commands[] = $builder->rolloutStatus($cluster, $deploymentName, kubeconfigPath: $kubeconfigPath);
        }

        instant_remote_process($commands, $service->server);
        $service->applications()->update(['status' => 'running']);
        $service->databases()->update(['status' => 'running']);
        ServiceStatusChanged::dispatch($service->environment->project->team->id);
    }

    private function manifestOptions(KubernetesCluster $cluster): array
    {
        return [
            'namespace' => $cluster->namespace,
            'create_namespace' => $cluster->create_namespace,
            'ingress_class' => $cluster->ingress_class,
            'ingress_tls_secret' => $cluster->ingress_tls_secret,
            'ingress_annotations' => $cluster->ingress_annotations,
            'service_type' => $cluster->service_type,
            'service_account_name' => $cluster->service_account_name,
            'create_service_account' => $cluster->create_service_account,
            'image_pull_secrets' => $cluster->image_pull_secrets,
            'storage_class' => $cluster->storage_class,
            'storage_size' => $cluster->storage_size,
            'replicas' => $cluster->replicas,
            'autoscaling' => $cluster->autoscaling_enabled,
            'min_replicas' => $cluster->min_replicas,
            'max_replicas' => $cluster->max_replicas,
            'target_cpu_utilization_percentage' => $cluster->target_cpu_utilization_percentage,
            'node_selector' => $cluster->node_selector,
            'tolerations' => $cluster->tolerations,
            'pod_disruption_budget_enabled' => $cluster->pod_disruption_budget_enabled,
            'pod_disruption_budget_min_available' => $cluster->pod_disruption_budget_min_available,
        ];
    }
}
