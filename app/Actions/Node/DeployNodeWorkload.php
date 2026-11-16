<?php

namespace App\Actions\Node;

use App\Actions\Node\Build\StartNodeBuild;
use App\Jobs\DeployNodeWorkloadJob;
use App\Models\NodeBuild;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Deploys the latest configuration of a cluster application on every server of it, like the
 * Deploy button of the v4 application heading. A Git application deploys through a build: its
 * job publishes the revision and deploys it. The caller authorizes `deploy` on the workload.
 */
class DeployNodeWorkload
{
    use AsAction;

    /**
     * @return array{operation: ?NodeOperation, build: ?NodeBuild} The operation of the first server, or of the
     *                                                             active deployment when one runs already; null when another operation is active.
     */
    public function handle(NodeWorkload $workload, User $user): array
    {
        if (! $workload->nodes()->exists()) {
            throw new RuntimeException('This application is not placed on any server.');
        }
        if ($workload->buildsFromSource()) {
            return ['operation' => null, 'build' => StartNodeBuild::run($workload, $user)];
        }
        $revision = PrepareNodeWorkloadRevision::run($workload);
        $operation = null;
        foreach ($workload->nodes()->orderBy('nodes.id')->get() as $node) {
            $deployment = CreateDeploymentOperation::run($node, $revision, $user, 'newer');
            if ($deployment['created']) {
                DeployNodeWorkloadJob::dispatch($deployment['operation']->id);
                $operation ??= $deployment['operation'];
            } elseif (in_array($deployment['operation']->command_type, NodeOperation::DEPLOYMENT_COMMAND_TYPES, true)) {
                $operation ??= $deployment['operation'];
            }
        }

        return ['operation' => $operation, 'build' => null];
    }
}
