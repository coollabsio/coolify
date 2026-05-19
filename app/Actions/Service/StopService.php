<?php

namespace App\Actions\Service;

use App\Actions\Server\CleanupDocker;
use App\Enums\ProcessStatus;
use App\Events\ServiceStatusChanged;
use App\Models\KubernetesCluster;
use App\Models\Server;
use App\Models\Service;
use App\Services\Kubernetes\KubernetesKubectlCommandBuilder;
use App\Services\Kubernetes\KubernetesServiceManifestGenerator;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

class StopService
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Service $service, bool $deleteConnectedNetworks = false, bool $dockerCleanup = true)
    {
        try {
            // Cancel any in-progress deployment activities so status doesn't stay stuck at "starting"
            Activity::where('properties->type_uuid', $service->uuid)
                ->where(function ($q) {
                    $q->where('properties->status', ProcessStatus::IN_PROGRESS->value)
                        ->orWhere('properties->status', ProcessStatus::QUEUED->value);
                })
                ->each(function ($activity) {
                    $activity->properties = $activity->properties->put('status', ProcessStatus::CANCELLED->value);
                    $activity->save();
                });

            if ($service->destination instanceof KubernetesCluster) {
                return $this->stopKubernetesService($service, $deleteConnectedNetworks);
            }

            $server = $service->destination->server;
            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }

            $containersToStop = [];
            $applications = $service->applications()->get();
            foreach ($applications as $application) {
                $containersToStop[] = "{$application->name}-{$service->uuid}";
            }
            $dbs = $service->databases()->get();
            foreach ($dbs as $db) {
                $containersToStop[] = "{$db->name}-{$service->uuid}";
            }

            if (! empty($containersToStop)) {
                $this->stopContainersInParallel($containersToStop, $server);
            }

            if ($deleteConnectedNetworks) {
                $service->deleteConnectedNetworks();
            }
            if ($dockerCleanup) {
                CleanupDocker::dispatch($server, false, false);
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        } finally {
            ServiceStatusChanged::dispatch($service->environment->project->team->id);
        }
    }

    private function stopContainersInParallel(array $containersToStop, Server $server): void
    {
        $timeout = count($containersToStop) > 5 ? 10 : 30;
        $commands = [];
        $containerList = implode(' ', $containersToStop);
        $commands[] = "docker stop -t $timeout $containerList";
        $commands[] = "docker rm -f $containerList";
        instant_remote_process(
            command: $commands,
            server: $server,
            throwError: false
        );
    }

    private function stopKubernetesService(Service $service, bool $deleteResources = false): ?string
    {
        try {
            $cluster = $service->destination;
            $server = $cluster->server;

            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }

            $builder = new KubernetesKubectlCommandBuilder;
            $kubeconfigPath = $cluster->effectiveKubeconfigPath();
            $commands = ['mkdir -p '.escapeshellarg($cluster->configurationDirectory())];

            if (filled($cluster->kubeconfig)) {
                $commands[] = $builder->writeKubeconfig($cluster->storedKubeconfigPath(), $cluster->kubeconfig);
                $kubeconfigPath = $cluster->storedKubeconfigPath();
            }

            if (blank($kubeconfigPath)) {
                return 'Kubernetes kubeconfig is not configured';
            }

            if ($deleteResources) {
                $commands[] = $builder->deleteServiceResources($cluster, (string) $service->uuid, $kubeconfigPath);
            } else {
                $compose = Yaml::parse($service->docker_compose ?: $service->docker_compose_raw) ?: [];
                foreach ((new KubernetesServiceManifestGenerator)->resourceNames($service, $compose) as $deploymentName) {
                    $commands[] = $builder->scaleDeployment($cluster, $deploymentName, 0, $kubeconfigPath);
                }
            }

            instant_remote_process($commands, $server, throwError: false);
            $service->applications()->update(['status' => 'exited']);
            $service->databases()->update(['status' => 'exited']);

            return null;
        } catch (\Exception $e) {
            return $e->getMessage();
        } finally {
            ServiceStatusChanged::dispatch($service->environment->project->team->id);
        }
    }
}
