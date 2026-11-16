<?php

namespace App\Actions\Node;

use App\Enums\NodeWorkloadAction;
use App\Jobs\ManageNodeWorkloadJob;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Queues a restart, stop, start, or container removal of a cluster application on every server
 * of it. A server that refuses the action (for example, another operation is active) does not
 * stop the others. The caller authorizes `deploy` on the workload.
 */
class QueueNodeWorkloadLifecycle
{
    use AsAction;

    /**
     * @return array{operations: list<NodeOperation>, failures: list<string>} The failures are `<server>: <reason>`.
     */
    public function handle(NodeWorkload $workload, NodeWorkloadAction $action, User $user): array
    {
        if (! $workload->nodes()->exists()) {
            throw new RuntimeException('This application is not placed on any server.');
        }
        $revision = $workload->revisions()->latest('id')->firstOrFail();
        $operations = [];
        $failures = [];
        foreach ($workload->nodes()->orderBy('nodes.id')->get() as $node) {
            try {
                $operation = CreateLifecycleOperation::run($node, $revision, $action, $user);
                ManageNodeWorkloadJob::dispatch($operation->id);
                $operations[] = $operation;
            } catch (RuntimeException $exception) {
                $failures[] = "{$node->name}: {$exception->getMessage()}";
            }
        }

        return ['operations' => $operations, 'failures' => $failures];
    }
}
