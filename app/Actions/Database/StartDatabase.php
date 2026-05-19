<?php

namespace App\Actions\Database;

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

class StartDatabase
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database)
    {
        $server = $database->destination->server;
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }
        if ($database->destination instanceof KubernetesCluster) {
            return $this->startKubernetesDatabase($database);
        }

        switch ($database->getMorphClass()) {
            case StandalonePostgresql::class:
                $activity = StartPostgresql::run($database);
                break;
            case StandaloneRedis::class:
                $activity = StartRedis::run($database);
                break;
            case StandaloneMongodb::class:
                $activity = StartMongodb::run($database);
                break;
            case StandaloneMysql::class:
                $activity = StartMysql::run($database);
                break;
            case StandaloneMariadb::class:
                $activity = StartMariadb::run($database);
                break;
            case StandaloneKeydb::class:
                $activity = StartKeydb::run($database);
                break;
            case StandaloneDragonfly::class:
                $activity = StartDragonfly::run($database);
                break;
            case StandaloneClickhouse::class:
                $activity = StartClickhouse::run($database);
                break;
        }
        if ($database->is_public && $database->public_port) {
            StartDatabaseProxy::dispatch($database);
        }

        return $activity;
    }

    private function startKubernetesDatabase(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database): ?string
    {
        $cluster = $database->destination;
        $builder = new KubernetesKubectlCommandBuilder;
        $generator = new KubernetesDatabaseManifestGenerator;
        $manifestDirectory = "{$database->workdir()}/kubernetes";
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
            return 'Kubernetes kubeconfig is not configured';
        }

        $manifestYaml = $generator->toYaml($database, $this->manifestOptions($cluster));
        $commands[] = $builder->writeManifest($manifestPath, $manifestYaml);

        if ($cluster->create_namespace) {
            $commands[] = $builder->ensureNamespace($cluster, $kubeconfigPath);
        }

        $commands[] = $builder->serverSideDryRun($cluster, $manifestPath, $kubeconfigPath);
        $commands[] = $builder->apply($cluster, $manifestPath, $kubeconfigPath);
        $commands[] = $builder->rolloutStatusStatefulSet($cluster, $generator->resourceName($database), kubeconfigPath: $kubeconfigPath);
        instant_remote_process($commands, $cluster->server);
        $database->update(['status' => 'running']);
        ServiceStatusChanged::dispatch($database->environment->project->team->id);

        return null;
    }

    private function manifestOptions(KubernetesCluster $cluster): array
    {
        return [
            'namespace' => $cluster->namespace,
            'create_namespace' => $cluster->create_namespace,
            'service_type' => $cluster->service_type,
            'image_pull_secrets' => $cluster->image_pull_secrets,
            'storage_class' => $cluster->storage_class,
            'storage_size' => $cluster->storage_size,
        ];
    }
}
