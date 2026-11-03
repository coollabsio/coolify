<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

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

    private static function helperContainersCommand(): string
    {
        return 'docker container ps --format \'{{json .}}\' | jq -s \'map(select(.Image|test("(^|/)coollabsio/coolify-helper(:|@)")))\'';
    }

    /**
     * Removes helper containers of finished deployments and the S3 upload containers (`backup-of-<execution uuid>`)
     * of database backups that are not running. Imports and file browsers also run in helper containers, so
     * other containers stay.
     */
    public function handle(): void
    {
        try {
            $containers = instant_remote_process_with_timeout([self::helperContainersCommand()], $this->server, false);
            $helperContainers = collect(json_decode((string) $containers, true) ?: [])
                ->filter(fn (mixed $container): bool => is_array($container) && filled(data_get($container, 'ID')) && filled(data_get($container, 'Names')));

            if ($helperContainers->isEmpty()) {
                return;
            }

            $finishedDeploymentUuids = ApplicationDeploymentQueue::query()
                ->whereIn('deployment_uuid', $helperContainers->pluck('Names')->all())
                ->whereNotIn('status', [
                    ApplicationDeploymentStatus::IN_PROGRESS->value,
                    ApplicationDeploymentStatus::QUEUED->value,
                ])
                ->pluck('deployment_uuid')
                ->all();

            $runningBackupUploadNames = ScheduledDatabaseBackupExecution::query()
                ->whereIn('uuid', $helperContainers->pluck('Names')->map(fn (string $name): string => Str::after($name, 'backup-of-'))->all())
                ->where('status', 'running')
                ->pluck('uuid')
                ->map(fn (string $uuid): string => "backup-of-{$uuid}")
                ->all();

            foreach ($helperContainers as $container) {
                $isFinishedDeployment = in_array($container['Names'], $finishedDeploymentUuids, true);
                // A worker that is killed during the upload never removes its upload container.
                $isOrphanedBackupUpload = str_starts_with($container['Names'], 'backup-of-')
                    && ! in_array($container['Names'], $runningBackupUploadNames, true);
                if (! $isFinishedDeployment && ! $isOrphanedBackupUpload) {
                    continue;
                }

                \Log::info('CleanupHelperContainersJob - Removing orphaned helper container', [
                    'container' => $container['Names'],
                    'id' => $container['ID'],
                ]);

                instant_remote_process_with_timeout(['docker container rm -f '.escapeshellarg($container['ID'])], $this->server, false);
            }
        } catch (\Throwable $e) {
            send_internal_notification('CleanupHelperContainersJob failed with error: '.$e->getMessage());
        }
    }
}
