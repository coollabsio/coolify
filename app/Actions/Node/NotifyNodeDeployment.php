<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\NodeBuild;
use App\Models\NodeOperation;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Notifications\Node\ClusterDeploymentFailed;
use App\Notifications\Node\ClusterDeploymentSuccess;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Sends the one deploy notification of a cluster application, preview, or stack, like v4
 * ApplicationDeploymentJob. A deploy on more than one server sends one notification when the
 * deploy ended on every server, like a v4 multi-server deploy. A cancelled deploy sends none.
 *
 * Only a deploy with a `notification_group` sends: a deploy, a rollback, or a deploy after a
 * build that a user or a webhook started. A deploy that the reconciler, a move, a restore, or a
 * stack starts has no group. A failure to send does not change the deploy.
 */
class NotifyNodeDeployment
{
    /** Called after the commit of an end status of an operation. */
    public static function forOperation(NodeOperation $operation): void
    {
        if ($operation->notification_group === null || $operation->notified_at !== null) {
            return;
        }
        try {
            $group = NodeOperation::query()->where('notification_group', $operation->notification_group)->orderBy('id')->get();
            // A server can end before the operation of the next server exists: wait for the size of the group.
            if ($group->count() < (int) ($group->min('notification_group_size') ?? 1)
                || $group->contains(fn (NodeOperation $member): bool => ! $member->status->isFinal() || $member->notified_at !== null)) {
                return;
            }
            // The worker that sets the marker sends. Another worker that saw the same end status stops here.
            $claimed = NodeOperation::query()
                ->where('notification_group', $operation->notification_group)
                ->whereNull('notified_at')
                ->update(['notified_at' => now()]);
            if ($claimed === 0) {
                return;
            }
            self::sendForGroup($group);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Called by the creator of a group after its last server, also after an error. A server that
     * had an active operation already, or that the error stopped, has no operation in the group:
     * the size becomes the number of operations that exist, and the group sends when they ended.
     */
    public static function finishGroup(string $notificationGroup): void
    {
        try {
            $count = NodeOperation::query()->where('notification_group', $notificationGroup)->count();
            if ($count === 0) {
                return;
            }
            NodeOperation::query()->where('notification_group', $notificationGroup)->where('notification_group_size', '>', $count)->update(['notification_group_size' => $count]);
            self::forOperation(NodeOperation::query()->where('notification_group', $notificationGroup)->orderBy('id')->first());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** A build that failed before its deploy. The caller changed its status to failed, so it sends once. */
    public static function forFailedBuild(NodeBuild $build): void
    {
        try {
            $build->refresh();
            $workload = NodeWorkload::query()->withoutGlobalScopes()->find($build->node_workload_id);
            // A stack build sends with the stack deploy. A build that started a deploy before it failed sends with that deploy.
            if ($workload === null || $build->skip_deploy || NodeOperation::query()->where('notification_group', self::buildGroup($build))->exists()) {
                return;
            }
            $url = self::url('project.cluster-application.build.show', $workload, ['build_uuid' => $build->uuid]);
            $workload->team()?->notify(new ClusterDeploymentFailed($workload, $build->uuid, $url, $build->commit_sha));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** The end of a stack deploy: one notification for the stack, not one for each service. */
    public static function forStack(NodeStack $stack): void
    {
        try {
            $stack->refresh();
            if (! in_array($stack->deploy_status, ['deployed', 'failed'], true)) {
                return;
            }
            $claimed = NodeStack::query()->withoutGlobalScopes()->whereKey($stack->id)->whereNull('deploy_notified_at')->update(['deploy_notified_at' => now()]);
            if ($claimed === 0) {
                return;
            }
            $url = base_url().route('project.cluster-stack.show', [
                'project_uuid' => $stack->project?->uuid,
                'environment_uuid' => $stack->environment?->uuid,
                'stack_uuid' => $stack->uuid,
            ], false);
            $notification = $stack->deploy_status === 'deployed'
                ? new ClusterDeploymentSuccess($stack, $stack->uuid, $url, $stack->commit_sha)
                : new ClusterDeploymentFailed($stack, $stack->uuid, $url, $stack->commit_sha);
            $stack->team?->notify($notification);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @param Collection<int, NodeOperation> $group */
    private static function sendForGroup(Collection $group): void
    {
        $failed = $group->first(fn (NodeOperation $member): bool => in_array($member->status, [NodeOperationStatus::FAILED, NodeOperationStatus::TIMED_OUT], true));
        if ($failed === null && $group->every(fn (NodeOperation $member): bool => $member->status === NodeOperationStatus::CANCELLED)) {
            return;
        }
        // Like v4, a failure links to the first deploy that did not succeed.
        $linked = $failed ?? $group->first(fn (NodeOperation $member): bool => $member->status === NodeOperationStatus::SUCCEEDED) ?? $group->first();
        $workload = NodeWorkload::query()->withoutGlobalScopes()->find($linked->node_workload_id);
        if ($workload === null) {
            return;
        }
        $url = self::url('project.cluster-application.deployment.show', $workload, ['deployment_uuid' => $linked->uuid]);
        $commit = str_starts_with((string) $linked->notification_group, 'build:')
            ? NodeBuild::query()->where('uuid', substr((string) $linked->notification_group, strlen('build:')))->value('commit_sha')
            : null;
        $notification = $failed === null
            ? new ClusterDeploymentSuccess($workload, $linked->uuid, $url, $commit)
            : new ClusterDeploymentFailed($workload, $linked->uuid, $url, $commit);
        $workload->team()?->notify($notification);
    }

    /** The notification group of the deploy that a build starts. */
    public static function buildGroup(NodeBuild $build): string
    {
        return 'build:'.$build->uuid;
    }

    /** @param array<string, string> $parameters */
    private static function url(string $route, NodeWorkload $workload, array $parameters): string
    {
        return base_url().route($route, [
            'project_uuid' => $workload->project?->uuid,
            'environment_uuid' => $workload->environment?->uuid,
            'workload_uuid' => $workload->uuid,
            ...$parameters,
        ], false);
    }
}
