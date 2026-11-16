<?php

namespace App\Jobs;

use App\Actions\Node\CleanupNodeImages;
use App\Actions\Node\TransitionOperation;
use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Services\ScheduledJobDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

/** Runs an image cleanup operation of a Node (decision 0027). It waits while a build or a deploy runs there. */
class CleanupNodeImagesJob implements ShouldQueue
{
    use Queueable;

    public const WAIT_SECONDS = 30;

    /** The prune of Sentinel (at most 10 minutes) and a margin. */
    public int $timeout = 700;

    public int $tries = 0;

    /** A scheduled run passes the Node and its occurrence; the job creates the operation. */
    public function __construct(public ?int $operationId, public ?string $occurrenceUuid = null, public ?int $nodeId = null) {}

    public function retryUntil(): Carbon
    {
        return now()->addHours(2);
    }

    public function handle(): void
    {
        $deliveries = app(ScheduledJobDeliveryService::class);
        $claimToken = $this->job?->uuid() ?? (string) $this->occurrenceUuid;
        if ($this->occurrenceUuid !== null && ! $deliveries->claim($this->occurrenceUuid, $claimToken)) {
            return;
        }
        $operation = $this->operation();
        if ($operation === null) {
            if ($this->occurrenceUuid !== null) {
                $deliveries->fail($this->occurrenceUuid, $claimToken);
            }

            return;
        }
        $finished = CleanupNodeImages::run($operation);
        if ($finished === null) {
            $this->release(self::WAIT_SECONDS);

            return;
        }
        if ($this->occurrenceUuid !== null) {
            $finished->status === NodeOperationStatus::SUCCEEDED
                ? $deliveries->complete($this->occurrenceUuid, $claimToken)
                : $deliveries->fail($this->occurrenceUuid, $claimToken);
        }
    }

    /** A retry of a scheduled run finds the operation that its first attempt created. */
    private function operation(): ?NodeOperation
    {
        if ($this->operationId !== null) {
            return NodeOperation::query()->with('node')->find($this->operationId);
        }
        $node = Node::query()->where('is_usable', true)->find($this->nodeId);
        if ($node === null || $node->supportsCapability(CleanupNodeImages::COMMAND_TYPE) !== true) {
            return null;
        }

        return CleanupNodeImages::queue($node, null, 'schedule')->load('node');
    }

    public function failed(?Throwable $exception): void
    {
        $operation = $this->operationId !== null
            ? NodeOperation::query()->find($this->operationId)
            : NodeOperation::query()->where('node_id', $this->nodeId)->where('command_type', CleanupNodeImages::COMMAND_TYPE)->latest('id')->first();
        if ($operation !== null && ! $operation->status->isFinal() && $operation->status->canTransitionTo(NodeOperationStatus::FAILED)) {
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: 'The image cleanup stopped before completion, or a build or a deploy ran on the server for more than 2 hours.');
        }
        if ($this->occurrenceUuid !== null) {
            app(ScheduledJobDeliveryService::class)->fail($this->occurrenceUuid, $this->job?->uuid() ?? $this->occurrenceUuid);
        }
    }
}
