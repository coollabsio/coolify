<?php

namespace App\Jobs;

use App\Actions\Server\CleanupDocker;
use App\Events\DockerCleanupDone;
use App\Models\DockerCleanupExecution;
use App\Models\Server;
use App\Notifications\Server\DockerCleanupFailed;
use App\Notifications\Server\DockerCleanupSuccess;
use App\Services\ScheduledJobDeliveryService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class DockerCleanupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = CleanupDocker::JOB_TIMEOUT;

    public $tries = 1;

    public ?string $usageBefore = null;

    /**
     * A manual run waits for a running cleanup (released and tried again), so its exceptions must not
     * count as reasons to try again.
     */
    public $maxExceptions = 1;

    /**
     * Seconds a manual run waits before it tries again while another cleanup holds the server lock.
     */
    public const MANUAL_RELEASE_DELAY = 60;

    public ?DockerCleanupExecution $execution_log = null;

    /**
     * Shares the per-server lock with queued CleanupDocker runs, so two cleanups never run on
     * one server at once. A scheduled run that finds the lock held marks its occurrence
     * skipped (not missed); a stop-triggered run is dropped. A manual run is released and tried
     * again until the running cleanup finishes (see retryUntil()). The lock outlives the job
     * timeout, so a new run cannot start while a timed-out run is still finishing.
     */
    public function middleware(): array
    {
        $withoutOverlapping = ScheduledJobDeliveryService::withoutOverlapping(CleanupDocker::overlapLockKey($this->server), $this->occurrenceUuid)
            ->shared()
            ->expireAfter(CleanupDocker::overlapLockExpiresAfter());

        return [
            $this->manualCleanup
                ? $withoutOverlapping->releaseAfter(self::MANUAL_RELEASE_DELAY)
                : $withoutOverlapping->dontRelease(),
        ];
    }

    /**
     * A manual run may wait for one full cleanup that holds the lock. Fixed at dispatch, so a manual run of a
     * killed worker, which comes back after retry_after, fails instead of running late. Other runs try once.
     */
    public function retryUntil(): ?\DateTimeInterface
    {
        if (! $this->manualCleanup) {
            return null;
        }

        return now()->addSeconds(CleanupDocker::overlapLockExpiresAfter() + self::MANUAL_RELEASE_DELAY * 2);
    }

    public function __construct(
        public Server $server,
        public bool $manualCleanup = false,
        public bool $deleteUnusedVolumes = false,
        public bool $deleteUnusedNetworks = false,
        public ?string $occurrenceUuid = null,
    ) {
        $this->onQueue(maintenance_queue());
    }

    public function handle(): void
    {
        if ($this->occurrenceUuid && ! app(ScheduledJobDeliveryService::class)->claim($this->occurrenceUuid, $this->job?->uuid() ?? $this->occurrenceUuid)) {
            return;
        }

        $failed = false;

        try {
            $this->execution_log = DockerCleanupExecution::create([
                'server_id' => $this->server->id,
            ]);
            $this->rememberExecution();

            if (! $this->server->isFunctional()) {
                $this->execution_log->update([
                    'status' => 'failed',
                    'message' => 'Server is not functional (unreachable, unusable, or disabled)',
                    'finished_at' => Carbon::now()->toImmutable(),
                ]);

                return;
            }

            $this->usageBefore = $this->server->getDiskUsage();

            if ($this->manualCleanup || $this->server->settings->force_docker_cleanup) {
                $cleanup_log = CleanupDocker::run(
                    server: $this->server,
                    deleteUnusedVolumes: $this->deleteUnusedVolumes,
                    deleteUnusedNetworks: $this->deleteUnusedNetworks
                );
                $usageAfter = $this->server->getDiskUsage();
                $message = ($this->manualCleanup ? 'Manual' : 'Forced').' Docker cleanup job executed successfully. Disk usage before: '.$this->usageBefore.'%, Disk usage after: '.$usageAfter.'%.';

                $this->execution_log->update([
                    'status' => 'success',
                    'message' => $message,
                    'cleanup_log' => $cleanup_log,
                ]);

                $this->server->team?->notify(new DockerCleanupSuccess($this->server, $message));
                event(new DockerCleanupDone($this->execution_log));

                return;
            }

            if (str($this->usageBefore)->isEmpty() || $this->usageBefore === null || $this->usageBefore === 0) {
                $cleanup_log = CleanupDocker::run(
                    server: $this->server,
                    deleteUnusedVolumes: $this->deleteUnusedVolumes,
                    deleteUnusedNetworks: $this->deleteUnusedNetworks
                );
                $message = 'Docker cleanup job executed successfully, but no disk usage could be determined.';

                $this->execution_log->update([
                    'status' => 'success',
                    'message' => $message,
                    'cleanup_log' => $cleanup_log,
                ]);

                $this->server->team?->notify(new DockerCleanupSuccess($this->server, $message));
                event(new DockerCleanupDone($this->execution_log));

                return;
            }

            if ($this->usageBefore >= $this->server->settings->docker_cleanup_threshold) {
                $cleanup_log = CleanupDocker::run(
                    server: $this->server,
                    deleteUnusedVolumes: $this->deleteUnusedVolumes,
                    deleteUnusedNetworks: $this->deleteUnusedNetworks
                );
                $usageAfter = $this->server->getDiskUsage();
                $diskSaved = $this->usageBefore - $usageAfter;

                if ($diskSaved > 0) {
                    $message = 'Saved '.$diskSaved.'% disk space. Disk usage before: '.$this->usageBefore.'%, Disk usage after: '.$usageAfter.'%.';
                } else {
                    $message = 'Docker cleanup job executed successfully, but no disk space was saved. Disk usage before: '.$this->usageBefore.'%, Disk usage after: '.$usageAfter.'%.';
                }

                $this->execution_log->update([
                    'status' => 'success',
                    'message' => $message,
                    'cleanup_log' => $cleanup_log,
                ]);

                $this->server->team?->notify(new DockerCleanupSuccess($this->server, $message));
                event(new DockerCleanupDone($this->execution_log));
            } else {
                $message = 'No cleanup needed for '.$this->server->name;

                $this->execution_log->update([
                    'status' => 'success',
                    'message' => $message,
                ]);

                $this->server->team?->notify(new DockerCleanupSuccess($this->server, $message));
                event(new DockerCleanupDone($this->execution_log));
            }
        } catch (\Throwable $e) {
            $failed = true;
            if ($this->execution_log) {
                $this->execution_log->update([
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ]);
                event(new DockerCleanupDone($this->execution_log));
            }
            $this->server->team?->notify(new DockerCleanupFailed($this->server, 'Docker cleanup job failed with the following error: '.$e->getMessage()));
            throw $e;
        } finally {
            if (! $failed && $this->occurrenceUuid) {
                app(ScheduledJobDeliveryService::class)->complete($this->occurrenceUuid, $this->job?->uuid() ?? $this->occurrenceUuid);
            }

            if ($this->execution_log) {
                $this->execution_log->update([
                    'finished_at' => Carbon::now()->toImmutable(),
                ]);
            }

            if (! $failed && $this->executionCacheKey()) {
                Cache::forget($this->executionCacheKey());
            }
        }
    }

    /**
     * failed() runs on a fresh job copy built from the queue payload (for example after a worker timeout),
     * so the execution this run created is kept under the queue job uuid instead of on the job.
     */
    private function rememberExecution(): void
    {
        if ($this->executionCacheKey()) {
            Cache::put($this->executionCacheKey(), $this->execution_log->id, now()->addSeconds((int) config('queue.connections.redis.retry_after', 86400) + 3600));
        }
    }

    private function executionCacheKey(): ?string
    {
        $jobUuid = $this->job?->uuid();

        return $jobUuid ? 'docker-cleanup-execution:'.$jobUuid : null;
    }

    public function failed(?\Throwable $exception): void
    {
        if ($this->occurrenceUuid) {
            app(ScheduledJobDeliveryService::class)->fail($this->occurrenceUuid, $this->job?->uuid() ?? $this->occurrenceUuid);
        }

        // Only this run's own execution. A run that never started (e.g. a manual run whose wait for the
        // server lock ran out) created none and must not fail another running cleanup.
        $executionId = $this->executionCacheKey() ? Cache::pull($this->executionCacheKey()) : null;
        $execution = $executionId ? DockerCleanupExecution::query()->find($executionId) : null;

        if (! $execution) {
            return;
        }

        $message = $exception?->getMessage() ?? 'Docker cleanup job failed without an exception.';

        $updated = DockerCleanupExecution::query()
            ->whereKey($execution->id)
            ->where('status', 'running')
            ->whereNull('finished_at')
            ->update([
                'status' => 'failed',
                'message' => $message,
                'finished_at' => Carbon::now()->toImmutable(),
            ]);

        if ($updated === 0) {
            return;
        }

        $execution->refresh();
        event(new DockerCleanupDone($execution));
        $this->server->team?->notify(new DockerCleanupFailed($this->server, 'Docker cleanup job failed with the following error: '.$message));
    }
}
