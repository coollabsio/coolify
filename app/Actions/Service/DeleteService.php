<?php

namespace App\Actions\Service;

use App\Actions\Server\CleanupDocker;
use App\Models\KubernetesCluster;
use App\Models\Service;
use App\Services\Kubernetes\KubernetesKubectlCommandBuilder;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteService
{
    use AsAction;

    public function handle(Service $service, bool $deleteVolumes, bool $deleteConnectedNetworks, bool $deleteConfigurations, bool $dockerCleanup)
    {
        $server = data_get($service, 'server');

        try {
            if ($service->destination instanceof KubernetesCluster) {
                $server = $service->destination->server;
                $this->deleteKubernetesService($service, $deleteVolumes);

                return;
            }

            if ($deleteVolumes && $server->isFunctional()) {
                $storagesToDelete = collect([]);

                $service->environment_variables()->delete();
                $commands = [];
                foreach ($service->applications()->get() as $application) {
                    $storages = $application->persistentStorages()->get();
                    foreach ($storages as $storage) {
                        $storagesToDelete->push($storage);
                    }
                }
                foreach ($service->databases()->get() as $database) {
                    $storages = $database->persistentStorages()->get();
                    foreach ($storages as $storage) {
                        $storagesToDelete->push($storage);
                    }
                }
                foreach ($storagesToDelete as $storage) {
                    $commands[] = 'docker volume rm -f '.escapeshellarg($storage->name);
                }

                // Execute volume deletion first, this must be done first otherwise volumes will not be deleted.
                if (! empty($commands)) {
                    foreach ($commands as $command) {
                        $result = instant_remote_process([$command], $server, false);
                        if ($result !== null && $result !== 0) {
                            Log::error('Error deleting volumes: '.$result);
                        }
                    }
                }
            }

            if ($deleteConnectedNetworks) {
                $service->deleteConnectedNetworks();
            }

            instant_remote_process(["docker rm -f $service->uuid"], $server, throwError: false);
        } catch (\Exception $e) {
            throw new \RuntimeException($e->getMessage());
        } finally {
            if ($deleteConfigurations) {
                $service->deleteConfigurations();
            }
            foreach ($service->applications()->get() as $application) {
                $application->forceDelete();
            }
            foreach ($service->databases()->get() as $database) {
                $database->forceDelete();
            }
            foreach ($service->scheduled_tasks as $task) {
                $task->delete();
            }
            $service->tags()->detach();
            $service->forceDelete();

            if ($dockerCleanup && ! ($service->destination instanceof KubernetesCluster)) {
                CleanupDocker::dispatch($server, false, false);
            }
        }
    }

    private function deleteKubernetesService(Service $service, bool $deleteVolumes): void
    {
        $cluster = $service->destination;
        $server = $cluster->server;

        if (! $server->isFunctional()) {
            throw new \RuntimeException('Server is not functional');
        }

        $builder = new KubernetesKubectlCommandBuilder;
        $kubeconfigPath = $cluster->effectiveKubeconfigPath();
        $commands = ['mkdir -p '.escapeshellarg($cluster->configurationDirectory())];

        if (filled($cluster->kubeconfig)) {
            $commands[] = $builder->writeKubeconfig($cluster->storedKubeconfigPath(), $cluster->kubeconfig);
            $kubeconfigPath = $cluster->storedKubeconfigPath();
        }

        if (blank($kubeconfigPath)) {
            throw new \RuntimeException('Kubernetes kubeconfig is not configured');
        }

        $commands[] = $builder->deleteServiceResources($cluster, (string) $service->uuid, $kubeconfigPath);

        if ($deleteVolumes) {
            $commands[] = $builder->deleteServicePersistentVolumeClaims($cluster, (string) $service->uuid, $kubeconfigPath);
        }

        instant_remote_process($commands, $server, throwError: false);
    }
}
