<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProcessStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Services\RcloneTransfer;
use App\Support\ResourceStartActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class CleanupHelperContainersJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Release the per-server lock after an hour if a worker dies before finishing.
     */
    public int $uniqueFor = 3600;

    public function __construct(public Server $server) {}

    /**
     * Keep the lock per server; a class-wide lock queued only one server per cleanup run.
     */
    public function uniqueId(): string
    {
        return $this->server->uuid;
    }

    /**
     * The job that starts an rclone container creates its execution or activity before it, except a database
     * import, which creates its activity right after it. Younger containers are kept.
     */
    private const RCLONE_MIN_AGE_SECONDS = 900;

    private static function helperContainersCommand(): string
    {
        return 'docker container ps --format \'{{json .}}\' | jq -s \'map(select(.Image|test("(^|/)coollabsio/coolify-helper(:|@)")))\'';
    }

    public function handle(): void
    {
        try {
            // Get all active deployments on this server
            $activeDeployments = ApplicationDeploymentQueue::where('server_id', $this->server->id)
                ->whereIn('status', [
                    ApplicationDeploymentStatus::IN_PROGRESS->value,
                    ApplicationDeploymentStatus::QUEUED->value,
                ])
                ->pluck('deployment_uuid')
                ->toArray();

            \Log::info('CleanupHelperContainersJob - Active deployments', [
                'server' => $this->server->name,
                'active_deployment_uuids' => $activeDeployments,
            ]);

            $activeTransfers = $this->activeTransferOwners();
            $containers = instant_remote_process_with_timeout([self::helperContainersCommand()], $this->server, false);
            $helperContainers = collect(json_decode($containers));

            if ($helperContainers->count() > 0) {
                foreach ($helperContainers as $container) {
                    $containerId = data_get($container, 'ID');
                    $containerName = data_get($container, 'Names');

                    // Check if this container belongs to an active deployment
                    $isActiveDeployment = false;
                    foreach ($activeDeployments as $deploymentUuid) {
                        if (str_contains($containerName, $deploymentUuid)) {
                            $isActiveDeployment = true;
                            break;
                        }
                    }

                    if ($isActiveDeployment || self::belongsToActiveTransfer((string) $containerName, $activeTransfers)) {
                        \Log::info('CleanupHelperContainersJob - Skipping active deployment container', [
                            'container' => $containerName,
                            'id' => $containerId,
                        ]);

                        continue;
                    }

                    \Log::info('CleanupHelperContainersJob - Removing orphaned helper container', [
                        'container' => $containerName,
                        'id' => $containerId,
                    ]);

                    instant_remote_process_with_timeout(['docker container rm -f '.$containerId], $this->server, false);
                }
            }
            $this->removeOrphanedRcloneContainers($activeTransfers);
        } catch (\Throwable $e) {
            send_internal_notification('CleanupHelperContainersJob failed with error: '.$e->getMessage());
        }
    }

    /**
     * An rclone container has the storage config with its password or SSH key. It stays until the end of its sleep
     * when a worker timeout kills the backup or import job before the job removes it.
     *
     * @param  Collection<int, string>  $activeTransfers
     */
    private function removeOrphanedRcloneContainers(Collection $activeTransfers): void
    {
        $output = instant_remote_process_with_timeout([
            'docker container ps --filter label='.RcloneTransfer::LABEL." --format '{{json .}}'",
        ], $this->server, false);
        $containers = collect(explode("\n", (string) $output))
            ->map(fn (string $line): mixed => json_decode(trim($line), true))
            ->filter(fn (mixed $container): bool => is_array($container) && filled($container['ID'] ?? null));
        if ($containers->isEmpty()) {
            return;
        }

        foreach ($containers as $container) {
            $name = (string) ($container['Names'] ?? '');
            $createdAt = Carbon::createFromFormat('Y-m-d H:i:s O', substr((string) ($container['CreatedAt'] ?? ''), 0, 25));
            if (! $createdAt || $createdAt->gt(now()->subSeconds(self::RCLONE_MIN_AGE_SECONDS))) {
                continue;
            }
            if (self::belongsToActiveTransfer($name, $activeTransfers)) {
                continue;
            }

            \Log::info('CleanupHelperContainersJob - Removing orphaned rclone container', ['container' => $name]);
            instant_remote_process_with_timeout(['docker container rm -f '.escapeshellarg((string) $container['ID'])], $this->server, false);
        }
    }

    /**
     * @param  Collection<int, string>  $activeTransfers
     */
    private static function belongsToActiveTransfer(string $containerName, Collection $activeTransfers): bool
    {
        return preg_match('/^(backup-of|volume-backup|volume-upload|s3-restore|backup-decompress)-(.+)$/', $containerName, $matches) === 1
            && $activeTransfers->contains($matches[2]);
    }

    /**
     * UUIDs of the running backups and database imports. Their upload and restore containers (helper or rclone) are
     * named "backup-of-", "volume-backup-", "volume-upload-", "s3-restore-", or "backup-decompress-" and the UUID.
     *
     * @return Collection<int, string>
     */
    private function activeTransferOwners(): Collection
    {
        $imports = Activity::query()
            ->where('properties->operation', ResourceStartActivity::DATABASE_IMPORT_OPERATION)
            ->whereIn('properties->status', [ProcessStatus::QUEUED->value, ProcessStatus::IN_PROGRESS->value])
            ->get()
            ->map(fn (Activity $activity): string => (string) data_get($activity, 'properties.operation_uuid'));

        return ScheduledDatabaseBackupExecution::query()->where('status', 'running')->pluck('uuid')
            ->merge(ScheduledVolumeBackupExecution::query()->where('status', 'running')->pluck('uuid'))
            ->merge($imports)
            ->filter()
            ->values();
    }
}
