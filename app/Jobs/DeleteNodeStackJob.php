<?php

namespace App\Jobs;

use App\Actions\Node\DeleteNodeVolumes;
use App\Actions\Shared\DeleteScheduledVolumeBackup;
use App\Models\NodeStack;
use App\Models\NodeVolumeCopy;
use App\Models\NodeWorkload;
use App\Models\Team;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes a stack: each service through the deletion of a cluster application, then the stack.
 * A service that cannot be deleted stays, with the notification of DeleteNodeWorkloadJob, and
 * keeps the stack, so a retry deletes the rest.
 */
class DeleteNodeStackJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const WAIT_FOR_OPERATIONS_SECONDS = 1800;

    /** Waiting for an active operation releases the job; any exception fails it. */
    public int $maxExceptions = 1;

    public int $timeout = 900;

    public function __construct(
        public int $stackId,
        public ?int $requestedById = null,
        public bool $deleteVolumes = true,
    ) {
        $this->onQueue('high');
        $budget = NodeWorkload::query()->where('node_stack_id', $stackId)->pluck('id')
            ->sum(fn (int $workloadId): int => (new DeleteNodeWorkloadJob($workloadId))->timeout);
        $this->timeout = max($this->timeout, $budget + 120);
    }

    public function uniqueId(): string
    {
        return (string) $this->stackId;
    }

    public function uniqueFor(): int
    {
        return $this->timeout + self::WAIT_FOR_OPERATIONS_SECONDS + 60;
    }

    public function retryUntil(): Carbon
    {
        return now()->addSeconds(self::WAIT_FOR_OPERATIONS_SECONDS);
    }

    public function handle(): void
    {
        $stack = NodeStack::query()->withoutGlobalScopes()->find($this->stackId);
        if ($stack === null) {
            return;
        }
        // The delete of a service waits for its active deploy or lifecycle action. It runs inline
        // here, where it cannot release itself, so the stack job waits for every service instead.
        $services = $stack->workloads()->withoutGlobalScopes()->get();
        if ($services->contains(fn (NodeWorkload $workload): bool => DeleteNodeWorkloadJob::hasActiveOperation($workload))) {
            $this->release(15);

            return;
        }
        foreach ($services->pluck('id') as $workloadId) {
            try {
                (new DeleteNodeWorkloadJob($workloadId, $this->requestedById, deleteVolumes: $this->deleteVolumes))->handle();
            } catch (Throwable $exception) {
                // DeleteNodeWorkloadJob notified the team. The other services are still deleted.
                report($exception);
            }
        }
        if (NodeWorkload::query()->withoutGlobalScopes()->where('node_stack_id', $stack->id)->exists()) {
            return;
        }
        // The volumes that the services shared (decision 0024) go with the stack, after every container is gone.
        $this->deleteStackVolumes($stack);
        $stack->delete();
    }

    /**
     * Deletes the backup schedules of the stack volumes and, unless the volumes are kept, their S3
     * archives and their data on each server. A server that cannot delete its volume keeps it as an
     * orphan volume, which its server page lists, and the team is notified.
     */
    private function deleteStackVolumes(NodeStack $stack): void
    {
        $failures = [];
        foreach ($stack->volumes()->with(['node', 'copies.node'])->get() as $volume) {
            foreach ($volume->scheduledBackups()->get() as $schedule) {
                try {
                    if ($this->deleteVolumes) {
                        DeleteScheduledVolumeBackup::run($schedule, deleteLocalArchives: false);

                        continue;
                    }
                } catch (Throwable $exception) {
                    Log::warning('Stack volume backup archives could not be deleted.', ['stack_uuid' => $stack->uuid, 'backup_uuid' => $schedule->uuid, 'error' => $exception->getMessage()]);
                }
                $schedule->delete();
            }
            if (! $this->deleteVolumes) {
                continue;
            }
            $locations = $volume->copies->map(fn (NodeVolumeCopy $copy): array => [$copy->node, $copy->setRelation('volume', $volume)->podmanName()]);
            if ($volume->node !== null) {
                $locations->prepend([$volume->node, $volume->podmanName()]);
            }
            foreach ($locations as [$node, $name]) {
                try {
                    DeleteNodeVolumes::run($node, [$name]);
                } catch (Throwable $exception) {
                    $failures[] = "{$node->name}: {$exception->getMessage()}";
                }
            }
        }
        if ($failures === []) {
            return;
        }
        Log::warning('Stack volumes could not be deleted.', ['stack_uuid' => $stack->uuid, 'failures' => $failures]);
        Team::query()->find($stack->team_id)?->notify(new GeneralNotification(
            "The shared volumes of the deleted stack '{$stack->name}' could not be deleted on every server. "
            .'They remain as orphan volumes on the server page. Error: '.implode(' ', $failures),
            success: false,
        ));
    }
}
