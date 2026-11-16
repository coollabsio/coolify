<?php

namespace App\Actions\Node;

use App\Jobs\DeployNodeStackJob;
use App\Models\NodeStack;
use App\Models\User;
use App\Services\NodeStack\NodeStackCompose;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Queues the deployment of a stack: each service deploys after the services it depends on
 * (decision 0023). A stack that mounts the Docker socket needs a team admin or owner. The
 * caller authorizes `deploy` on the stack.
 */
class DeployNodeStack
{
    use AsAction;

    public function handle(NodeStack $stack, User $user): NodeStack
    {
        if (NodeStackCompose::mountsContainerSocket($stack->compose_parsed ?? []) && ! $user->isAdminOfTeam((int) $stack->team_id)) {
            throw new RuntimeException('This stack mounts the Docker socket, which controls every container on its server. Only a team admin or owner can deploy it.');
        }
        if ($stack->deploy_status === 'deploying') {
            throw new RuntimeException('This stack is deploying. Wait until the deployment finishes.');
        }
        $stack->update(['deploy_status' => 'queued', 'deploy_error' => null]);
        DeployNodeStackJob::dispatch($stack->id, $user->id);

        return $stack;
    }
}
