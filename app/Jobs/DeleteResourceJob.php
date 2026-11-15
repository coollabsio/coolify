<?php

namespace App\Jobs;

use App\Actions\Application\CleanupCancelledDeployment;
use App\Actions\Application\StopApplication;
use App\Actions\Database\DeleteScheduledDatabaseBackup;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\DeleteService;
use App\Actions\Service\StopService;
use App\Actions\Shared\DeleteScheduledVolumeBackup;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteResourceJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Application|ApplicationPreview|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|StandaloneSqlite $resource,
        public bool $deleteVolumes = true,
        public bool $deleteConnectedNetworks = true,
        public bool $deleteConfigurations = true,
        public bool $dockerCleanup = true,
        public bool $deleteFromCoolifyOnly = false,
        public bool $deleteBackups = false,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        if ($this->resource instanceof ApplicationPreview) {
            $this->deleteApplicationPreview();

            return;
        }

        if ($this->deleteFromCoolifyOnly) {
            // No remote calls on this path: the backup files stay, and only their schedule records go.
            $this->deleteLocalResource();

            return;
        }

        try {
            switch ($this->resource->type()) {
                case 'application':
                    $this->cancelActiveDeployments($this->resource);
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
                case 'standalone-sqlite':
                    StopDatabase::run($this->resource, dockerCleanup: $this->dockerCleanup, keepAnonymousDataVolume: false);
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
            // A resource can always be deleted from Coolify, also when its server does not respond.
            Log::warning('Remote cleanup failed while deleting resource; continuing with local deletion.', [
                'resource_id' => $this->resource->id,
                'resource_type' => $this->resource->type(),
                'error' => $e->getMessage(),
            ]);

            // A deleted server has nothing left to clean up. Otherwise tell the team what may remain.
            $server = data_get($this->resource, 'destination.server');
            if ($server) {
                $this->resource->team()?->notify(new GeneralNotification(
                    "'{$this->resource->name}' was removed from Coolify, but its Docker resources could not be removed from server '{$server->name}' and may still exist there. Error: ".remove_iip($e->getMessage()),
                    success: false,
                ));
            }
        }

        if ($this->deleteBackups) {
            $this->deleteBackupFiles();
        }

        $maintenanceServers = data_get($this->resource, 'is_maintenance_enabled') ? $this->resource->maintenanceServers() : null;

        $this->deleteLocalResource();

        if ($maintenanceServers) {
            try {
                // Removes the maintenance route of the deleted resource.
                $this->resource->syncMaintenancePage($maintenanceServers);
            } catch (\Throwable $e) {
                Log::warning('Maintenance page cleanup failed while deleting resource.', [
                    'resource_id' => $this->resource->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function deleteLocalResource(): void
    {
        DB::transaction(function (): void {
            // Without this, the scheduler keeps running backups of volumes that no longer exist in Coolify.
            $this->volumeBackupSchedules()->each->delete();

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
            || $this->resource instanceof StandaloneClickhouse
            || $this->resource instanceof StandaloneSqlite;
    }

    /**
     * Deletes the volume and database backup files of the resource, locally and on S3, with their schedules. A schedule
     * whose files cannot be deleted does not stop the others; its record goes with the resource.
     */
    private function deleteBackupFiles(): void
    {
        $server = data_get($this->resource, 'server') ?? data_get($this->resource, 'destination.server');
        $errors = [];

        foreach ($this->volumeBackupSchedules()->concat($this->databaseBackupSchedules()) as $backup) {
            try {
                if ($backup instanceof ScheduledVolumeBackup) {
                    DeleteScheduledVolumeBackup::run($backup, $server, deleteLocalArchives: $server !== null);
                } else {
                    DeleteScheduledDatabaseBackup::run($backup, $server, deleteLocalBackups: $server !== null);
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors === []) {
            return;
        }

        Log::warning('Backup cleanup failed while deleting resource; continuing with local deletion.', [
            'resource_id' => $this->resource->id,
            'resource_type' => $this->resource->type(),
            'errors' => $errors,
        ]);
        $this->resource->team()?->notify(new GeneralNotification(
            "'{$this->resource->name}' was removed from Coolify, but some of its backup files could not be deleted and may still exist on the server or on S3. Coolify no longer manages them. Error: ".remove_iip(implode(' ', array_unique($errors))),
            success: false,
        ));
    }

    /**
     * @return Collection<int, ScheduledDatabaseBackup>
     */
    private function databaseBackupSchedules(): Collection
    {
        $databases = match (true) {
            $this->resource instanceof Service => $this->resource->databases()->get(),
            $this->isDatabase() => collect([$this->resource]),
            default => collect(),
        };

        return $databases->flatMap(fn ($database) => $database->scheduledBackups()->get());
    }

    /**
     * @return Collection<int, ScheduledVolumeBackup>
     */
    private function volumeBackupSchedules(): Collection
    {
        $resources = $this->resource instanceof Service
            ? $this->resource->applications()->get()->concat($this->resource->databases()->get())
            : collect([$this->resource]);

        return $resources->flatMap(fn ($resource) => $resource->persistentStorages()->get()
            ->concat($resource->fileStorages()->get())
            ->flatMap(fn ($storage) => $storage->scheduledBackups()->get()));
    }

    /**
     * The deployment records go with the application, and CleanupHelperContainersJob keeps helper containers
     * without a record. A running build would go on, on the deployment or build server, until it ends.
     */
    private function cancelActiveDeployments(Application $application): void
    {
        $teamId = $application->team()?->id;
        if ($teamId === null) {
            return;
        }

        $activeDeployments = ApplicationDeploymentQueue::where('application_id', $application->id)
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->get();

        foreach ($activeDeployments as $deployment) {
            $deployment->update([
                'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                'finished_at' => now(),
            ]);
            $deployment->addLogEntry('Deployment cancelled: the application was deleted.', 'stderr');
            try {
                CleanupCancelledDeployment::run($deployment, $teamId);
            } catch (\Throwable $e) {
                Log::warning('Helper container cleanup failed while deleting application.', [
                    'deployment_uuid' => $deployment->deployment_uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // The cancelled deployments free their build slots for queued deployments of other applications.
        Server::whereTeamId($teamId)
            ->whereKey($activeDeployments->pluck('server_id')->filter()->unique())
            ->get()
            ->each(fn (Server $server) => next_after_cancel($server));
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
                next_after_cancel($server, $application);
            } catch (\Throwable $e) {
                \Log::warning("Failed to advance deployment queue after deleting preview {$this->resource->id}: {$e->getMessage()}");
            }
        }

        try {
            if ($server->isSwarm()) {
                $escapedStackName = escapeshellarg("{$application->uuid}-{$pull_request_id}");
                instant_remote_process(["docker stack rm {$escapedStackName}"], $server);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $application, $pull_request_id)->toArray();
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
