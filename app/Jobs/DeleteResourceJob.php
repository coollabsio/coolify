<?php

namespace App\Jobs;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\DeleteService;
use App\Actions\Service\StopService;
use App\Actions\Shared\DeleteScheduledVolumeBackup;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    public function handle(): void
    {
        if ($this->resource instanceof ApplicationPreview) {
            $this->deleteApplicationPreview();

            return;
        }

        try {
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
                    app(DeleteService::class)->cleanupRemote(
                        $this->resource,
                        $this->deleteVolumes,
                        $this->deleteConnectedNetworks,
                        $this->deleteConfigurations,
                    );
                    break;
            }

            if (! $this->resource instanceof Service) {
                if ($this->deleteConfigurations) {
                    $this->resource->deleteConfigurations();
                }
                if ($this->deleteVolumes) {
                    $this->resource->deleteVolumes();
                }
                if ($this->deleteConnectedNetworks && $this->resource->type() === 'application') {
                    $this->resource->deleteConnectedNetworks();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Remote cleanup failed while deleting resource; continuing with local deletion.', [
                'resource_id' => $this->resource->id,
                'resource_type' => $this->resource->type(),
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->deleteScheduledVolumeBackups();
        } catch (\Throwable $e) {
            Log::warning('Remote backup cleanup failed while deleting resource; continuing with local deletion.', [
                'resource_id' => $this->resource->id,
                'resource_type' => $this->resource->type(),
                'error' => $e->getMessage(),
            ]);
        }

        DB::transaction(function (): void {
            if ($this->resource instanceof Service) {
                app(DeleteService::class)->deleteLocal($this->resource);

                return;
            }

            if ($this->deleteVolumes) {
                $this->resource->persistentStorages()->delete();
            }
            $this->resource->fileStorages()->delete();

            if ($this->isDatabase()) {
                $this->resource->sslCertificates()->delete();
                $this->resource->scheduledBackups()->delete();
                $this->resource->tags()->detach();
            }
            $this->resource->environment_variables()->delete();
            $this->resource->forceDelete();
        });

    }

    private function isDatabase(): bool
    {
        return $this->resource instanceof StandalonePostgresql
            || $this->resource instanceof StandaloneRedis
            || $this->resource instanceof StandaloneMongodb
            || $this->resource instanceof StandaloneMysql
            || $this->resource instanceof StandaloneMariadb
            || $this->resource instanceof StandaloneKeydb
            || $this->resource instanceof StandaloneDragonfly
            || $this->resource instanceof StandaloneClickhouse;
    }

    private function deleteScheduledVolumeBackups(): void
    {
        $server = data_get($this->resource, 'server') ?? data_get($this->resource, 'destination.server');
        $resources = $this->resource instanceof Service
            ? $this->resource->applications()->get()->concat($this->resource->databases()->get())
            : collect([$this->resource]);

        foreach ($resources as $resource) {
            $storages = $resource->persistentStorages()->get()->concat($resource->fileStorages()->get());

            foreach ($storages as $storage) {
                foreach ($storage->scheduledBackups()->get() as $backup) {
                    DeleteScheduledVolumeBackup::run($backup, $server);
                }
            }
        }
    }

    private function deleteApplicationPreview(): void
    {
        $application = $this->resource->application;

        if (! $application) {
            $this->deleteApplicationPreviewLocally();

            return;
        }

        $server = $application->destination?->server;
        if (! $server) {
            $this->deleteApplicationPreviewLocally();

            return;
        }
        $pull_request_id = $this->resource->pull_request_id;

        // Ensure the preview is soft deleted (may already be done in Livewire component)
        if (! $this->resource->trashed()) {
            $this->resource->delete();
        }

        // Cancel any active deployments for this PR (same logic as API cancel_deployment)
        $activeDeployments = ApplicationDeploymentQueue::where('application_id', $application->id)
            ->where('pull_request_id', $pull_request_id)
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->get();

        $cancelledDeployments = 0;

        foreach ($activeDeployments as $activeDeployment) {
            try {
                // Mark deployment as cancelled
                $activeDeployment->update([
                    'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                ]);
                $cancelledDeployments++;

                // Add cancellation log entry
                $activeDeployment->addLogEntry('Deployment cancelled: Pull request closed.', 'stderr');

                // Check if helper container exists and kill it
                $deployment_uuid = $activeDeployment->deployment_uuid;
                $escapedDeploymentUuid = escapeshellarg($deployment_uuid);
                $checkCommand = "docker ps -a --filter name={$escapedDeploymentUuid} --format '{{.Names}}'";
                $containerExists = instant_remote_process([$checkCommand], $server);

                if ($containerExists && str($containerExists)->trim()->isNotEmpty()) {
                    instant_remote_process(["docker rm -f {$escapedDeploymentUuid}"], $server);
                    $activeDeployment->addLogEntry('Deployment container stopped.');
                } else {
                    $activeDeployment->addLogEntry('Helper container not yet started. Deployment will be cancelled when job checks status.');
                }

            } catch (\Throwable $e) {
                // Silently handle errors during deployment cancellation
            }
        }

        if ($cancelledDeployments > 0) {
            try {
                next_after_cancel($server);
            } catch (\Throwable $e) {
                \Log::warning("Failed to advance deployment queue after deleting preview {$this->resource->id}: {$e->getMessage()}");
            }
        }

        try {
            if ($server->isSwarm()) {
                $escapedStackName = escapeshellarg("{$application->uuid}-{$pull_request_id}");
                instant_remote_process(["docker stack rm {$escapedStackName}"], $server);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $application->id, $pull_request_id)->toArray();
                $this->stopPreviewContainers($containers, $server);
            }
        } catch (\Throwable $e) {
            // Log the error but don't fail the job
            \Log::warning('Error stopping preview containers for application '.$application->uuid.', PR #'.$pull_request_id.': '.$e->getMessage());
        }

        // Finally, force delete to trigger resource cleanup
        $this->resource->forceDelete();
    }

    private function deleteApplicationPreviewLocally(): void
    {
        DB::transaction(function (): void {
            $this->resource->persistentStorages()->delete();
            ApplicationPreview::withoutEvents(fn () => $this->resource->forceDelete());
        });
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
            dockerStopCommand($timeout, $containerList, $server),
            "docker rm -f $containerList",
        ];
        instant_remote_process(
            command: $commands,
            server: $server,
            throwError: false
        );
    }
}
