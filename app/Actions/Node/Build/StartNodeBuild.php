<?php

namespace App\Actions\Node\Build;

use App\Enums\NodeBuildStatus;
use App\Enums\NodeWorkloadSourceType;
use App\Jobs\DeployDockerfileWorkloadJob;
use App\Jobs\DeployRailpackWorkloadJob;
use App\Models\NodeBuild;
use App\Models\NodeWorkload;
use App\Models\User;
use App\Services\NodeStack\NodeStackCompose;
use App\Support\ContainerIsolation;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Queues a build of the workload's Git source or inline Dockerfile. A workload has at most one
 * active build; asking again returns it. A webhook passes the pushed commit;
 * without one, the build resolves the head of the branch. A rollback passes an earlier build,
 * whose source and commit the new build copies. A stack deploy builds without deploying
 * (`$skipDeploy`), then deploys its services in `depends_on` order.
 *
 * A push webhook (`$fromWebhook`) that comes while a build runs adds a waiting build, which starts
 * when the running build ends, like a queued v4 deployment. The v4 settings of the workload decide
 * which older webhook builds a newer push cancels. Manual, API, and rollback builds are never cancelled.
 */
class StartNodeBuild
{
    use AsAction;

    public function handle(NodeWorkload $workload, ?User $user, ?string $commitSha = null, ?NodeBuild $rebuildOf = null, bool $skipDeploy = false, bool $fromWebhook = false): NodeBuild
    {
        $superseded = collect();
        $build = DB::transaction(function () use ($workload, $user, $commitSha, $rebuildOf, $skipDeploy, $fromWebhook, &$superseded): NodeBuild {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            // The build deploys its revision; refuse before the build, not after it.
            if ($workload->mountsContainerSocket()) {
                NodeStackCompose::ensureMayMountContainerSocket($user, (int) $workload->team_id);
            }
            // The admin tier of the Docker options (decision 0032), before the build, not after it.
            ContainerIsolation::ensureMayUseAdminTier($user, (int) $workload->team_id, $workload->runtimeSettings()['runtime_options'] ?? []);
            if ($workload->node_stack_id !== null) {
                // The isolation options of a stack service are in the Compose file (decision 0036).
                $yaml = $workload->stack()->withoutGlobalScopes()->first()?->compose_parsed ?? [];
                $service = $yaml['services'][$workload->stack_service] ?? null;
                if (is_array($service)) {
                    NodeStackCompose::ensureMayUseAdminIsolation($user, (int) $workload->team_id, ['services' => [$workload->stack_service => $service]], 'This stack');
                }
            }
            if ($user !== null) {
                SelectBuilderNode::ensureMayBuild($workload, $user);
            }
            if ($rebuildOf !== null) {
                if ($workload->builds()->active()->exists()) {
                    throw new RuntimeException('A build of this application is running. Wait for it or cancel it, then roll back again.');
                }

                return $workload->builds()->create([
                    'requested_by_user_id' => $user?->id,
                    ...$rebuildOf->only(['source_type', 'git_repository', 'git_branch', 'commit_sha', 'base_directory', 'dockerfile_location', 'dockerfile']),
                    'status' => NodeBuildStatus::QUEUED,
                ]);
            }
            if (! $workload->buildsFromSource()) {
                throw new RuntimeException('This application has no source to build.');
            }
            $missingSource = $workload->source_type->usesGit()
                ? (blank($workload->git_repository) || blank($workload->git_branch) ? 'This application has no Git source to build.' : null)
                : (blank($workload->dockerfile) ? 'This application has no Dockerfile to build.' : null);
            if ($missingSource !== null) {
                throw new RuntimeException($missingSource);
            }
            $active = $workload->builds()->active()->orderBy('id')->get();
            if ($active->isNotEmpty() && ! $fromWebhook) {
                return $active->last();
            }
            $sameCommit = $commitSha === null ? null : $active->firstWhere('commit_sha', $commitSha);
            if ($sameCommit !== null) {
                return $sameCommit;
            }
            $superseded = $active->filter(fn (NodeBuild $build): bool => $build->is_webhook && (
                $workload->is_auto_cancel_building_deployments_enabled
                || ($workload->is_auto_cancel_queued_deployments_enabled && $build->is_waiting)
            ));

            return $workload->builds()->create([
                'is_webhook' => $fromWebhook,
                'is_waiting' => $active->count() > $superseded->count(),
                'requested_by_user_id' => $user?->id,
                'source_type' => $workload->source_type,
                'git_repository' => $workload->git_repository,
                // A preview builds the ref of its pull request; its git_branch is the base branch.
                'git_branch' => $workload->isPreview() && filled($workload->pull_request_ref) ? $workload->pull_request_ref : $workload->git_branch,
                'commit_sha' => $commitSha,
                'base_directory' => $workload->base_directory,
                'dockerfile_location' => $workload->dockerfile_location,
                'dockerfile' => $workload->dockerfile,
                'skip_deploy' => $skipDeploy,
                'status' => NodeBuildStatus::QUEUED,
            ]);
        });

        $superseded->each(fn (NodeBuild $old) => CancelNodeBuild::run($old, 'Cancelled by a newer push.'));
        if ($build->wasRecentlyCreated && ! $build->is_waiting) {
            self::dispatchBuild($build);
        }

        return $build;
    }

    public static function dispatchBuild(NodeBuild $build): void
    {
        match ($build->source_type) {
            NodeWorkloadSourceType::DOCKERFILE, NodeWorkloadSourceType::DOCKERFILE_INLINE => DeployDockerfileWorkloadJob::dispatch($build->id),
            NodeWorkloadSourceType::RAILPACK, NodeWorkloadSourceType::STATIC => DeployRailpackWorkloadJob::dispatch($build->id),
            NodeWorkloadSourceType::IMAGE => throw new RuntimeException('An image application is not built.'),
        };
    }
}
