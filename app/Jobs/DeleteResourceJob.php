<?php

namespace App\Jobs;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StopDatabase;
use App\Actions\Server\CleanupDocker;
use App\Actions\Service\DeleteService;
use App\Actions\Service\StopService;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class DeleteResourceJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Application|ApplicationPreview|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource,
        public bool $deleteVolumes = true,
        public bool $deleteConnectedNetworks = true,
        public bool $deleteConfigurations = true,
        public bool $dockerCleanup = true
    ) {
        $this->onQueue('high');
    }

    public function handle()
    {
        try {
            // Handle ApplicationPreview instances separately
            if ($this->resource instanceof ApplicationPreview) {
                $this->deleteApplicationPreview();

                return;
            }

            switch ($this->resource->type()) {
                case 'application':
                    StopApplication::run($this->resource, previewDeployments: true, dockerCleanup: $this->dockerCleanup);
                    break;
                case 'standalone-postgresql':
                case 'standalone-redis':
                case 'standalone-mongodb':
                case 'standalone-mysql':
                case 'standalone-mariadb':
                case 'standalone-keydb':
                case 'standalone-dragonfly':
                case 'standalone-clickhouse':
                    StopDatabase::run($this->resource, dockerCleanup: $this->dockerCleanup);
                    break;
                case 'service':
                    StopService::run($this->resource, $this->deleteConnectedNetworks, $this->dockerCleanup);
                    DeleteService::run($this->resource, $this->deleteVolumes, $this->deleteConnectedNetworks, $this->deleteConfigurations, $this->dockerCleanup);

                    return;
            }

            if ($this->deleteConfigurations) {
                $this->resource->deleteConfigurations();
            }
            if ($this->deleteVolumes) {
                $this->resource->deleteVolumes();
                $this->resource->persistentStorages()->delete();
            }
            $this->resource->fileStorages()->delete(); // these are file mounts which should probably have their own flag

            $isDatabase = $this->resource instanceof StandalonePostgresql
            || $this->resource instanceof StandaloneRedis
            || $this->resource instanceof StandaloneMongodb
            || $this->resource instanceof StandaloneMysql
            || $this->resource instanceof StandaloneMariadb
            || $this->resource instanceof StandaloneKeydb
            || $this->resource instanceof StandaloneDragonfly
            || $this->resource instanceof StandaloneClickhouse;

            if ($isDatabase) {
                $this->resource->sslCertificates()->delete();
                $this->resource->scheduledBackups()->delete();
                $this->resource->tags()->detach();
            }
            $this->resource->environment_variables()->delete();

            if ($this->deleteConnectedNetworks && $this->resource->type() === 'application') {
                $this->resource->deleteConnectedNetworks();
            }
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            $this->resource->forceDelete();
            if ($this->dockerCleanup) {
                $server = data_get($this->resource, 'server') ?? data_get($this->resource, 'destination.server');
                if ($server) {
                    CleanupDocker::dispatch($server, false, false);
                }
            }
            Artisan::queue('cleanup:stucked-resources');
        }
    }

    private function deleteApplicationPreview()
    {
        $application = $this->resource->application;
        $server = $application->destination->server;
        $pull_request_id = $this->resource->pull_request_id;

        // Ensure the preview is soft deleted (may already be done in Livewire component)
        if (! $this->resource->trashed()) {
            $this->resource->delete();
        }

        try {
            if ($server->isSwarm()) {
                instant_remote_process(["docker stack rm {$application->uuid}-{$pull_request_id}"], $server);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $application->id, $pull_request_id)->toArray();
                $this->stopPreviewContainers($containers, $server);
            }
        } catch (\Throwable $e) {
            // Log the error but don't fail the job
            ray('Error stopping preview containers: '.$e->getMessage());
        }

        // Finally, force delete to trigger resource cleanup
        $this->resource->forceDelete();
    }

    private function stopPreviewContainers(array $containers, $server, int $timeout = 30)
    {
        if (empty($containers)) {
            return;
        }

        $containerNames = [];
        foreach ($containers as $container) {
            $containerNames[] = str_replace('/', '', $container['Names']);
        }

        $containerList = implode(' ', array_map('escapeshellarg', $containerNames));
        $commands = [
            "docker stop --time=$timeout $containerList",
            "docker rm -f $containerList",
        ];

        instant_remote_process(
            command: $commands,
            server: $server,
            throwError: false
        );
    }
}
