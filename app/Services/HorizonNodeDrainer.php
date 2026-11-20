<?php

namespace App\Services;

use App\Actions\Application\CleanupCancelledDeployment;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\ScheduledTaskJob;
use App\Jobs\SyncGithubDeploymentJob;
use App\Jobs\VolumeBackupJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTaskExecution;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Throwable;

/**
 * Moves the running deployments and cron jobs of this Coolify Cloud worker node to the other node.
 *
 * @phpstan-type DrainedItem array{kind: string, resource: string, old_id: string, new_id: ?string, failure: ?string}
 * @phpstan-type CronRun array{kind: string, id: int, resource: string, execution: Model, server: ?Server, lock_key: string, containers: list<string>, restart: Closure(): mixed}
 */
class HorizonNodeDrainer
{
    public const DEPLOYMENT = 'deployment';

    public const SCHEDULED_TASK = 'scheduled_task';

    public const DATABASE_BACKUP = 'database_backup';

    public const VOLUME_BACKUP = 'volume_backup';

    public const MOVED_MESSAGE = 'Stopped: moved to another worker node.';

    private const PAUSE_WAIT_SECONDS = 30;

    public function preflightError(): ?string
    {
        if (! isCloud()) {
            return 'horizon:drain runs only on Coolify Cloud.';
        }

        $otherNodeRuns = $this->masters()->contains(fn ($master) => ! $this->isThisNode($master->name) && $master->status === 'running');
        if (! $otherNodeRuns) {
            return 'No Horizon master of another node is running, so no node can take the work.';
        }

        return null;
    }

    /**
     * Sends SIGUSR2 to the Horizon masters of this host and waits until they report paused.
     * Paused workers finish their current job and take no new one. Workers are never killed,
     * because Horizon restarts a killed worker unpaused.
     */
    public function pauseThisNode(): bool
    {
        foreach ($this->masters()->filter(fn ($master) => $this->isThisNode($master->name)) as $master) {
            $pid = (int) $master->pid;
            if ($pid > 0 && in_array('horizon', $this->arguments($pid), true)) {
                posix_kill($pid, SIGUSR2);
            }
        }

        $deadline = time() + self::PAUSE_WAIT_SECONDS;
        do {
            if ($this->masters()->doesntContain(fn ($master) => $this->isThisNode($master->name) && $master->status !== 'paused')) {
                return true;
            }
            sleep(1);
        } while (time() < $deadline);

        return false;
    }

    /**
     * @return list<DrainedItem>
     */
    public function runningWork(): array
    {
        $deployments = $this->runningDeployments()->map(fn (ApplicationDeploymentQueue $deployment) => $this->item(self::DEPLOYMENT, $deployment->application_name, $deployment->deployment_uuid));
        $crons = $this->cronRuns()->map(fn (array $run) => $this->item($run['kind'], $run['resource'], $run['execution']->uuid));

        return $deployments->concat($crons)->values()->all();
    }

    /**
     * @return list<DrainedItem>
     */
    public function drain(int $timeout): array
    {
        $deployments = $this->runningDeployments()->map(fn (ApplicationDeploymentQueue $deployment) => $this->moveDeployment($deployment));

        return $deployments->concat($this->moveCronRuns($this->cronRuns(), $timeout))->values()->all();
    }

    /**
     * Read by the failure paths of the cron jobs, so a run that the drain stopped sends no failure notification.
     */
    public static function wasMoved(string $kind, int $id): bool
    {
        return Cache::has(self::movedKey($kind, $id));
    }

    private static function movedKey(string $kind, int $id): string
    {
        return 'horizon-drain:moved:'.gethostname().":{$kind}:{$id}";
    }

    /**
     * @return Collection<int, ApplicationDeploymentQueue>
     */
    private function runningDeployments(): Collection
    {
        return ApplicationDeploymentQueue::query()
            ->where('horizon_job_worker', gethostname())
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->get();
    }

    /**
     * Cancels first, so the old and the new deployment never build at the same time. The copy goes
     * through the normal admission, so it waits for the concurrent build limit of the server.
     *
     * @return DrainedItem
     */
    private function moveDeployment(ApplicationDeploymentQueue $old): array
    {
        $item = fn (?string $newId, ?string $failure = null) => $this->item(self::DEPLOYMENT, $old->application_name, $old->deployment_uuid, $newId, $failure);

        $application = Application::query()->find($old->application_id);
        if (! $application) {
            return $item(null, 'The application no longer exists.');
        }
        $server = Server::query()->find($old->server_id);
        $destination = StandaloneDocker::query()->find($old->destination_id);
        if ($destination?->server_id !== $server?->id) {
            $destination = null;
        }

        $cancelled = ApplicationDeploymentQueue::query()
            ->whereKey($old->getKey())
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]) === 1;
        if (! $cancelled) {
            return $item(null, 'It ended before it was cancelled.');
        }
        $old->refresh();

        $newUuid = new_public_id();
        $old->addLogEntry(self::MOVED_MESSAGE, 'stderr');
        $failures = [];
        try {
            // The conditional update fires no model event.
            SyncGithubDeploymentJob::dispatchFor($old);
            CleanupCancelledDeployment::run($old, (int) $application->team()->id);
        } catch (Throwable $e) {
            $failures[] = 'The cleanup of the old deployment failed: '.$e->getMessage();
        }

        $scanGateBypassedBy = $old->scan_gate_bypassed_by === null ? null : (int) $old->scan_gate_bypassed_by;
        $pinnedImage = data_get($old->scan_gate_result, 'repo_digest');
        $newId = null;
        try {
            // is_webhook is set after admission: as a webhook deployment, the copy would cancel newer queued webhook deployments.
            $result = queue_application_deployment(
                application: $application,
                deployment_uuid: $newUuid,
                pull_request_id: (int) $old->pull_request_id,
                commit: $old->commit,
                force_rebuild: (bool) $old->force_rebuild,
                is_api: (bool) $old->is_api,
                restart_only: (bool) $old->restart_only,
                git_type: $old->git_type,
                server: $server,
                destination: $destination,
                only_this_server: (bool) $old->only_this_server,
                rollback: (bool) $old->rollback,
                docker_registry_image_tag: $old->docker_registry_image_tag,
                parent_deployment_uuid: $old->parent_deployment_uuid,
                scan_gate_bypassed_by: $scanGateBypassedBy,
                scan_gate_pinned_image: $scanGateBypassedBy !== null && is_string($pinnedImage) ? $pinnedImage : null,
                is_mcp: (bool) $old->is_mcp,
            );
            $newId = data_get($result, 'deployment_uuid');
            match (data_get($result, 'status')) {
                'queued' => $this->copyDeploymentOrigin($old, $newUuid),
                'skipped' => $failures[] = 'A deployment of the same commit was already queued, so no copy was made.',
                default => $failures[] = 'The copy was not queued: '.data_get($result, 'message', 'unknown reason'),
            };
        } catch (Throwable $e) {
            $failures[] = 'The copy was not queued: '.$e->getMessage();
        } finally {
            next_after_cancel($server, $application);
        }
        $old->addLogEntry($newId === null
            ? 'The new deployment was not queued. Deploy the application again.'
            : "The work continues in deployment {$newId}.", 'stderr');

        return $item($newId, $failures === [] ? null : implode(' ', $failures));
    }

    private function copyDeploymentOrigin(ApplicationDeploymentQueue $old, string $newUuid): void
    {
        ApplicationDeploymentQueue::query()->where('deployment_uuid', $newUuid)->update(['is_webhook' => (bool) $old->is_webhook]);
        if ($old->build_server_id !== null) {
            // The job saves its own choice when it already selected a build server.
            ApplicationDeploymentQueue::query()->where('deployment_uuid', $newUuid)->whereNull('build_server_id')->update(['build_server_id' => $old->build_server_id]);
        }
    }

    /**
     * @return Collection<int, CronRun>
     */
    private function cronRuns(): Collection
    {
        return $this->scheduledTaskRuns()->concat($this->databaseBackupRuns())->concat($this->volumeBackupRuns())->values();
    }

    /**
     * @return Collection<int, CronRun>
     */
    private function scheduledTaskRuns(): Collection
    {
        return ScheduledTaskExecution::query()
            ->where('worker_host', gethostname())
            ->where('status', 'running')
            ->whereHas('scheduledTask')
            ->with('scheduledTask')
            ->get()
            ->unique('scheduled_task_id')
            ->map(function (ScheduledTaskExecution $execution) {
                $task = $execution->scheduledTask;

                return [
                    'kind' => self::SCHEDULED_TASK,
                    'id' => $task->id,
                    'resource' => (string) $task->name,
                    'execution' => $execution,
                    'server' => $task->server(),
                    'lock_key' => $this->overlapLockKey(new ScheduledTaskJob($task)),
                    'containers' => [],
                    'restart' => fn () => ScheduledTaskJob::dispatch($task),
                ];
            });
    }

    /**
     * @return Collection<int, CronRun>
     */
    private function databaseBackupRuns(): Collection
    {
        return ScheduledDatabaseBackupExecution::query()
            ->where('worker_host', gethostname())
            ->where('status', 'running')
            ->whereHas('scheduledDatabaseBackup')
            ->with('scheduledDatabaseBackup')
            ->get()
            ->unique('scheduled_database_backup_id')
            ->map(function (ScheduledDatabaseBackupExecution $execution) {
                $backup = $execution->scheduledDatabaseBackup;

                return [
                    'kind' => self::DATABASE_BACKUP,
                    'id' => $backup->id,
                    'resource' => (string) ($backup->database?->name ?? $backup->uuid),
                    'execution' => $execution,
                    'server' => $backup->server(),
                    'lock_key' => $this->overlapLockKey(new DatabaseBackupJob($backup)),
                    'containers' => ["backup-of-{$execution->uuid}"],
                    'restart' => fn () => DatabaseBackupJob::dispatch($backup),
                ];
            });
    }

    /**
     * The archive and stream helpers use `volume-backup-*`, the S3 upload uses `volume-upload-*`. A removed
     * archive helper makes the backup script exit, and its trap starts the containers that it stopped.
     *
     * @return Collection<int, CronRun>
     */
    private function volumeBackupRuns(): Collection
    {
        return ScheduledVolumeBackupExecution::query()
            ->where('worker_host', gethostname())
            ->where('status', 'running')
            ->whereHas('scheduledVolumeBackup')
            ->with('scheduledVolumeBackup')
            ->get()
            ->unique('scheduled_volume_backup_id')
            ->map(function (ScheduledVolumeBackupExecution $execution) {
                $backup = $execution->scheduledVolumeBackup;

                return [
                    'kind' => self::VOLUME_BACKUP,
                    'id' => $backup->id,
                    'resource' => $backup->targetName(),
                    'execution' => $execution,
                    'server' => $backup->server(),
                    'lock_key' => $this->overlapLockKey(new VolumeBackupJob($backup)),
                    'containers' => ["volume-backup-{$execution->uuid}", "volume-upload-{$execution->uuid}"],
                    'restart' => fn () => VolumeBackupJob::dispatch($backup),
                ];
            });
    }

    private function overlapLockKey(ScheduledTaskJob|DatabaseBackupJob|VolumeBackupJob $job): string
    {
        return $job->middleware()[0]->getLockKey($job);
    }

    /**
     * Stops the runs and queues each one again after its job released the overlap lock, so the
     * new run is not dropped by WithoutOverlapping and never runs next to the old one.
     *
     * @param  Collection<int, CronRun>  $runs
     * @return list<DrainedItem>
     */
    private function moveCronRuns(Collection $runs, int $timeout): array
    {
        if ($runs->isEmpty()) {
            return [];
        }

        foreach ($runs as $run) {
            Cache::put(self::movedKey($run['kind'], $run['id']), true, now()->addSeconds($timeout + 300));
        }
        $this->killRemoteCommands($runs->pluck('server')->filter());
        foreach ($runs as $run) {
            if ($run['server'] && $run['containers'] !== []) {
                $names = implode(' ', array_map('escapeshellarg', $run['containers']));
                instant_remote_process(["docker rm -f {$names} >/dev/null 2>&1 || true"], $run['server'], throwError: false);
            }
        }

        $items = [];
        $pending = $runs->keyBy(fn (array $run) => "{$run['kind']}:{$run['id']}");
        $deadline = time() + $timeout;
        while (true) {
            foreach ($pending as $key => $run) {
                if ($this->lockIsFree($run['lock_key'])) {
                    $items[] = $this->restartCronRun($run);
                    $pending->forget($key);
                }
            }
            if ($pending->isEmpty() || time() >= $deadline) {
                break;
            }
            sleep(1);
        }

        foreach ($pending as $run) {
            $items[] = $this->item($run['kind'], $run['resource'], $run['execution']->uuid, null, "Still running after {$timeout} s, so it was not queued again.");
        }

        return $items;
    }

    /**
     * @param  CronRun  $run
     * @return DrainedItem
     */
    private function restartCronRun(array $run): array
    {
        $execution = $run['execution']->fresh();
        if ($execution && ! in_array($execution->status, ['running', 'failed'], true)) {
            return $this->item($run['kind'], $run['resource'], $execution->uuid, null, "Ended with status {$execution->status} before it was stopped.");
        }

        $execution?->update([
            'status' => 'failed',
            'message' => self::MOVED_MESSAGE,
            'finished_at' => $execution->finished_at ?? now(),
        ]);
        ($run['restart'])();

        return $this->item($run['kind'], $run['resource'], $run['execution']->uuid, 'queued again');
    }

    /**
     * Probes the lock with a one-second acquire, because the cache API cannot read a lock without taking it.
     */
    private function lockIsFree(string $key): bool
    {
        $lock = Cache::lock($key, 1);
        if (! $lock->get()) {
            return false;
        }
        $lock->release();

        return true;
    }

    /**
     * SIGKILL, not SIGTERM: ssh exits with 255 on SIGTERM, which the SSH retry handler treats as a
     * connection failure and answers by running the whole command batch again.
     *
     * @param  Collection<int, Server>  $servers
     */
    private function killRemoteCommands(Collection $servers): void
    {
        $targets = $servers->map(fn (Server $server) => "{$server->user}@{$server->ip}")->unique()->all();
        $parents = $this->processParents();
        $cronWorkers = array_filter(array_keys($parents), function (int $pid) {
            $arguments = $this->arguments($pid);

            return in_array('horizon:work', $arguments, true) && in_array('--queue='.crons_queue(), $arguments, true);
        });

        foreach (array_keys($parents) as $pid) {
            if (array_intersect($targets, $this->arguments($pid)) !== [] && $this->descendsFrom($pid, $cronWorkers, $parents)) {
                posix_kill($pid, SIGKILL);
            }
        }
    }

    /**
     * @param  array<int, int>  $ancestors
     * @param  array<int, int>  $parents
     */
    private function descendsFrom(int $pid, array $ancestors, array $parents): bool
    {
        $seen = [];
        while (isset($parents[$pid]) && ! isset($seen[$pid])) {
            $seen[$pid] = true;
            $pid = $parents[$pid];
            if (in_array($pid, $ancestors, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, int> Parent PID by PID.
     */
    private function processParents(): array
    {
        $parents = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $file) {
            $stat = @file_get_contents($file);
            if ($stat === false) {
                continue;
            }
            $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
            $parents[(int) basename(dirname($file))] = (int) ($fields[1] ?? 0);
        }

        return $parents;
    }

    /**
     * @return list<string>
     */
    private function arguments(int $pid): array
    {
        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");

        return $cmdline ? explode("\0", rtrim($cmdline, "\0")) : [];
    }

    /**
     * @return Collection<int, object>
     */
    private function masters(): Collection
    {
        return collect(app(MasterSupervisorRepository::class)->all());
    }

    private function isThisNode(string $masterName): bool
    {
        return str_starts_with($masterName, MasterSupervisor::basename().'-');
    }

    /**
     * @return DrainedItem
     */
    private function item(string $kind, string $resource, string $oldId, ?string $newId = null, ?string $failure = null): array
    {
        return ['kind' => $kind, 'resource' => $resource, 'old_id' => $oldId, 'new_id' => $newId, 'failure' => $failure];
    }
}
