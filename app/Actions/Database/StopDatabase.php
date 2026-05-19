<?php

namespace App\Actions\Database;

use App\Actions\Server\CleanupDocker;
use App\Events\ServiceStatusChanged;
use App\Models\KubernetesCluster;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Services\Kubernetes\KubernetesDatabaseManifestGenerator;
use App\Services\Kubernetes\KubernetesKubectlCommandBuilder;
use Lorisleiva\Actions\Concerns\AsAction;

class StopDatabase
{
    use AsAction;

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, bool $dockerCleanup = true, bool $deleteKubernetesResources = false, bool $deleteKubernetesVolumes = false)
    {
        try {
            if ($database->destination instanceof KubernetesCluster) {
                return $this->stopKubernetesDatabase($database, $deleteKubernetesResources, $deleteKubernetesVolumes);
            }

            $server = $database->destination->server;
            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }

            $this->stopContainer($database, $database->uuid, 30);

            // Reset restart tracking when database is manually stopped
            $database->update([
                'restart_count' => 0,
                'last_restart_at' => null,
                'last_restart_type' => null,
            ]);

            if ($dockerCleanup) {
                CleanupDocker::dispatch($server, false, false);
            }

            if ($database->is_public) {
                StopDatabaseProxy::run($database);
            }

            return 'Database stopped successfully';
        } catch (\Exception $e) {
            return 'Database stop failed: '.$e->getMessage();
        } finally {
            ServiceStatusChanged::dispatch($database->environment->project->team->id);
        }

    }

    private function stopKubernetesDatabase(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, bool $deleteResources = false, bool $deleteVolumes = false): string
    {
        $cluster = $database->destination;
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
            $commands[] = $builder->deleteDatabaseResources($cluster, (string) $database->uuid, $kubeconfigPath);

            if ($deleteVolumes) {
                $commands[] = $builder->deleteDatabasePersistentVolumeClaims($cluster, (string) $database->uuid, $kubeconfigPath);
            }
        } else {
            $name = (new KubernetesDatabaseManifestGenerator)->resourceName($database);
            $commands[] = $builder->scaleStatefulSet($cluster, $name, 0, $kubeconfigPath);
        }

        instant_remote_process($commands, $server, throwError: false);
        $database->update(['status' => 'exited', 'restart_count' => 0, 'last_restart_at' => null, 'last_restart_type' => null]);

        return 'Database stopped successfully';
    }

    private function stopContainer($database, string $containerName, int $timeout = 30): void
    {
        $server = $database->destination->server;
        instant_remote_process(command: [
            "docker stop -t $timeout $containerName",
            "docker rm -f $containerName",
        ], server: $server, throwError: false);
    }
}
