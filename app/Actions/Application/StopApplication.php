<?php

namespace App\Actions\Application;

use App\Actions\Server\CleanupDocker;
use App\Events\ServiceStatusChanged;
use App\Models\Application;
use App\Models\KubernetesCluster;
use App\Services\Kubernetes\KubernetesApplicationManifestGenerator;
use App\Services\Kubernetes\KubernetesKubectlCommandBuilder;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplication
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Application $application, bool $previewDeployments = false, bool $dockerCleanup = true)
    {
        if ($application->destination instanceof KubernetesCluster) {
            return $this->stopKubernetesApplication($application);
        }

        $servers = collect([$application->destination->server]);
        if ($application?->additional_servers?->count() > 0) {
            $servers = $servers->merge($application->additional_servers);
        }
        foreach ($servers as $server) {
            try {
                if (! $server->isFunctional()) {
                    return 'Server is not functional';
                }

                if ($server->isSwarm()) {
                    instant_remote_process(["docker stack rm {$application->uuid}"], $server);

                    return;
                }

                $containers = $previewDeployments
                    ? getCurrentApplicationContainerStatus($server, $application->id, includePullrequests: true)
                    : getCurrentApplicationContainerStatus($server, $application->id, 0);

                $containersToStop = $containers->pluck('Names')->toArray();
                $timeout = $application->settings->stopGracePeriodSeconds();

                foreach ($containersToStop as $containerName) {
                    instant_remote_process(command: [
                        "docker stop --time=$timeout $containerName",
                        "docker rm -f $containerName",
                    ], server: $server, throwError: false);
                }

                if ($application->build_pack === 'dockercompose') {
                    $application->deleteConnectedNetworks();
                }

                if ($dockerCleanup) {
                    CleanupDocker::dispatch($server, false, false);
                }
            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }

        // Reset restart tracking when application is manually stopped
        $application->update([
            'restart_count' => 0,
            'last_restart_at' => null,
            'last_restart_type' => null,
        ]);

        ServiceStatusChanged::dispatch($application->environment->project->team->id);
    }

    private function stopKubernetesApplication(Application $application): ?string
    {
        try {
            $cluster = $application->destination;
            $server = $cluster->server;

            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }

            $builder = new KubernetesKubectlCommandBuilder;
            $generator = new KubernetesApplicationManifestGenerator;
            $resourceName = $generator->resourceName($application);
            $kubeconfigPath = $cluster->effectiveKubeconfigPath();
            $commands = [
                'mkdir -p '.escapeshellarg($cluster->configurationDirectory()),
            ];

            if (filled($cluster->kubeconfig)) {
                $commands[] = $builder->writeKubeconfig($cluster->storedKubeconfigPath(), $cluster->kubeconfig);
                $kubeconfigPath = $cluster->storedKubeconfigPath();
            }

            if (blank($kubeconfigPath)) {
                return 'Kubernetes kubeconfig is not configured';
            }

            $commands[] = $builder->scaleDeployment($cluster, $resourceName, 0, $kubeconfigPath);
            instant_remote_process($commands, $server, throwError: false);
            $application->update([
                'status' => 'exited',
                'restart_count' => 0,
                'last_restart_at' => null,
                'last_restart_type' => null,
            ]);
            ServiceStatusChanged::dispatch($application->environment->project->team->id);

            return null;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}
