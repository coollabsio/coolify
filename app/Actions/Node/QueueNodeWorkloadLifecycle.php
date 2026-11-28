<?php

namespace App\Actions\Node;

use App\Enums\NodeWorkloadAction;
use App\Jobs\ManageNodeWorkloadJob;
use App\Models\NodeOperation;
use App\Models\NodeStack;
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

    /**
     * Queues the action on every service of a stack, like a v4 service. The caller authorizes
     * `deploy` on the stack.
     *
     * @return array{queued: bool, failures: list<string>} The failures are `<service>: <reason>`.
     */
    public static function forStack(NodeStack $stack, NodeWorkloadAction $action, User $user): array
    {
        $queued = false;
        $failures = [];
        foreach ($stack->workloads()->get() as $workload) {
            try {
                $result = self::run($workload, $action, $user);
            } catch (RuntimeException $exception) {
                $failures[] = "{$workload->stack_service}: {$exception->getMessage()}";

                continue;
            }
            $queued = $queued || $result['operations'] !== [];
            $failures = [...$failures, ...array_map(fn (string $failure): string => "{$workload->stack_service}: {$failure}", $result['failures'])];
        }

        return ['queued' => $queued, 'failures' => $failures];
    }
}
