<?php

namespace App\Actions\Node\Build;

use App\Enums\NodeBuildStatus;
use App\Enums\NodeWorkloadSourceType;
use App\Jobs\DeployDockerfileWorkloadJob;
use App\Jobs\DeployRailpackWorkloadJob;
use App\Models\NodeBuild;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Queues a build of the workload's Git source or inline Dockerfile. A workload has at most one
 * active build; asking again returns it. A webhook passes the pushed commit;
 * without one, the build resolves the head of the branch.
 */
class StartNodeBuild
{
    use AsAction;

    public function handle(NodeWorkload $workload, ?User $user, ?string $commitSha = null): NodeBuild
    {
        $build = DB::transaction(function () use ($workload, $user, $commitSha): NodeBuild {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            if (! $workload->buildsFromSource()) {
                throw new RuntimeException('This application has no source to build.');
            }
            $missingSource = $workload->source_type->usesGit()
                ? (blank($workload->git_repository) || blank($workload->git_branch) ? 'This application has no Git source to build.' : null)
                : (blank($workload->dockerfile) ? 'This application has no Dockerfile to build.' : null);
            if ($missingSource !== null) {
                throw new RuntimeException($missingSource);
            }
            $active = $workload->builds()->active()->latest('id')->first();
            if ($active !== null) {
                return $active;
            }

            return $workload->builds()->create([
                'requested_by_user_id' => $user?->id,
                'source_type' => $workload->source_type,
                'git_repository' => $workload->git_repository,
                'git_branch' => $workload->git_branch,
                'commit_sha' => $commitSha,
                'base_directory' => $workload->base_directory,
                'dockerfile_location' => $workload->dockerfile_location,
                'dockerfile' => $workload->dockerfile,
                'status' => NodeBuildStatus::QUEUED,
            ]);
        });

        if ($build->wasRecentlyCreated) {
            match ($build->source_type) {
                NodeWorkloadSourceType::DOCKERFILE, NodeWorkloadSourceType::DOCKERFILE_INLINE => DeployDockerfileWorkloadJob::dispatch($build->id),
                NodeWorkloadSourceType::RAILPACK, NodeWorkloadSourceType::STATIC => DeployRailpackWorkloadJob::dispatch($build->id),
                NodeWorkloadSourceType::IMAGE => throw new RuntimeException('An image application is not built.'),
            };
        }

        return $build;
    }
}
