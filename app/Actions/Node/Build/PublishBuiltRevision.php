<?php

namespace App\Actions\Node\Build;

use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\PrepareNodeWorkloadRevision;
use App\Jobs\DeployNodeWorkloadJob;
use App\Models\NodeBuild;
use App\Models\NodeRegistry;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Creates the revision of a successful build and deploys it to every placed
 * Node. Only now does a revision with the new image exist, so the reconciler,
 * moves, and recovery never see an image that is not built.
 */
class PublishBuiltRevision
{
    use AsAction;

    public function handle(NodeBuild $build, string $digest): NodeWorkloadRevision
    {
        $workload = DB::transaction(function () use ($build, $digest): NodeWorkload {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($build->node_workload_id);
            $current = $workload->revisions()->latest('id')->first();
            $workload->createRevision(
                NodeRegistry::HOST.'/'.$workload->uuid.'@'.$digest,
                $current?->configuration ?? ['restart_policy' => 'unless-stopped'],
                $current?->environment ?? [],
                $current?->secrets ?? [],
            );

            return $workload;
        });
        // The same step as a Deploy of an image application: it adds the current environment
        // (and everything else that is edited on the workload) as a newer revision when it changed.
        $revision = PrepareNodeWorkloadRevision::run($workload);
        $build->update(['image_digest' => $digest, 'node_workload_revision_id' => $revision->id]);

        foreach ($build->workload->nodes()->get() as $node) {
            $deployment = CreateDeploymentOperation::run($node, $revision, $build->requestedBy, 'missing');
            if ($deployment['created']) {
                DeployNodeWorkloadJob::dispatch($deployment['operation']->id);
            }
        }

        return $revision;
    }
}
