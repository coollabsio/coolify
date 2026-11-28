<?php

namespace App\Actions\Node;

use App\Actions\Node\Build\StartNodeBuild;
use App\Enums\NodeBuildStatus;
use App\Enums\NodeOperationStatus;
use App\Jobs\DeployNodeWorkloadJob;
use App\Models\Node;
use App\Models\NodeBuild;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\User;
use App\Support\NodeImages;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Rolls a cluster application back to the image of an earlier revision (decision 0017). The
 * rollback adds a new latest revision with that image and the current settings, environment
 * variables, and secrets of the workload, and deploys it like Deploy does, so rolling deploys
 * apply. A local image (decision 0030) is copied from a Node that still keeps it. When no Node
 * keeps it, the build of that image runs again from the same commit, and its job deploys the result.
 * An image in a registry is pulled by digest: the digest of the revision, or the digest that the tag
 * pointed to at the selected deployment. Without a known digest, the tag deploys again.
 */
class RollbackNodeWorkload
{
    use AsAction;

    /**
     * @return array{operation: ?NodeOperation, build: ?NodeBuild}
     */
    public function handle(NodeWorkload $workload, NodeWorkloadRevision $revision, ?User $user, ?NodeOperation $deployment = null): array
    {
        if ($revision->node_workload_id !== $workload->id) {
            throw new RuntimeException('This revision does not belong to this application.');
        }
        if ($deployment !== null && ($deployment->node_workload_id !== $workload->id || $deployment->node_workload_revision_id !== $revision->id
            || $deployment->command_type !== 'workload.deploy.v1' || $deployment->status !== NodeOperationStatus::SUCCEEDED)) {
            throw new RuntimeException('Only a successful deployment of this application can be rolled back to.');
        }
        // An older database image can fail to read data that a newer one wrote, and volume data is not rolled back.
        if ($workload->isDatabase()) {
            throw new RuntimeException('A database cannot be rolled back. Restore a backup instead.');
        }
        $current = $workload->revisions()->latest('id')->firstOrFail();
        $image = self::targetImage($revision, $deployment);
        if (! self::changesImage($workload, $image)) {
            throw new RuntimeException('This revision runs the current image. Deploy the application to apply other changes.');
        }
        $nodes = $workload->nodes()->orderBy('nodes.id')->get();
        if ($nodes->isEmpty()) {
            throw new RuntimeException('This application has no server. Move it to a server first.');
        }
        // The rollback revision becomes the latest revision, which reconciliation deploys later anyway. Check every
        // server first, so a rollback that reports an error does not run afterwards. The rollback keeps the
        // current settings, so the current revision has its resource reservations.
        foreach ($nodes as $node) {
            EnsureNodeAcceptsDeployment::run($node->fresh(['cluster']), $current);
        }

        if (NodeImages::isLocalImage($revision->image) && ! self::localImageAvailable($workload, $revision->image)) {
            return ['operation' => null, 'build' => StartNodeBuild::run($workload, $user, rebuildOf: $this->buildOf($workload, $revision))];
        }

        $rollback = PrepareNodeWorkloadRevision::run($workload, $image);
        $operation = null;
        $notificationGroup = (string) Str::uuid();
        try {
            foreach ($nodes as $node) {
                // The image is pinned by digest, or is the tag that the earlier revision ran; a pull of a newer tag would not roll back.
                $deployment = CreateDeploymentOperation::run($node, $rollback, $user, 'missing', notificationGroup: $notificationGroup, notificationGroupSize: $nodes->count());
                if ($deployment['created']) {
                    DeployNodeWorkloadJob::dispatch($deployment['operation']->id);
                    $operation ??= $deployment['operation'];
                }
            }
        } finally {
            NotifyNodeDeployment::finishGroup($notificationGroup);
        }
        if ($operation === null) {
            throw new RuntimeException('This application already has an active operation. The rollback revision deploys when it finishes, or deploy again then.');
        }

        return ['operation' => $operation, 'build' => null];
    }

    /**
     * The image that a rollback deploys. A registry tag is pinned to the digest that it pointed to at
     * the deployment (decision 0017): the selected one, or else the last one of the revision with a
     * known digest. A tag pushed again then does not run the newer image.
     */
    public static function targetImage(NodeWorkloadRevision $revision, ?NodeOperation $deployment = null): string
    {
        if ($deployment !== null) {
            return $deployment->setRelation('revision', $revision)->deployedImage() ?? $revision->image;
        }
        $pinned = NodeOperation::query()
            ->where('node_workload_revision_id', $revision->id)
            ->where('command_type', 'workload.deploy.v1')
            ->where('status', NodeOperationStatus::SUCCEEDED)
            ->whereNotNull('request->image_digest')
            ->latest('id')
            ->first();

        return $pinned?->setRelation('revision', $revision)->deployedImage() ?? $revision->image;
    }

    /** Whether a rollback to the image changes what the application runs. */
    public static function changesImage(NodeWorkload $workload, string $image): bool
    {
        $running = self::runningImages($workload);

        return $running !== [] && ! in_array($image, $running, true);
    }

    /**
     * The images that the application runs now: the image of the latest revision, and the image
     * that the last successful deployment of that revision ran. Empty without a revision.
     *
     * @return list<string>
     */
    public static function runningImages(NodeWorkload $workload): array
    {
        $current = $workload->revisions()->latest('id')->first();
        if ($current === null) {
            return [];
        }
        $running = NodeOperation::query()
            ->where('node_workload_revision_id', $current->id)
            ->where('command_type', 'workload.deploy.v1')
            ->where('status', NodeOperationStatus::SUCCEEDED)
            ->latest('id')
            ->first()
            ?->setRelation('revision', $current)
            ->deployedImage();

        return array_values(array_unique(array_filter([$current->image, $running])));
    }

    /** The image that the application runs now, pinned to its digest when Coolify knows it. */
    public static function runningImage(NodeWorkload $workload): ?string
    {
        $images = self::runningImages($workload);

        return $images === [] ? null : $images[array_key_last($images)];
    }

    /**
     * Whether a deploy of the local image finds it: every server of the application keeps it, or
     * an online server of the cluster keeps it and serves it to the others (decision 0030).
     */
    public static function localImageAvailable(NodeWorkload $workload, string $image): bool
    {
        $nodes = $workload->nodes()->orderBy('nodes.id')->get();

        return $nodes->isNotEmpty() && (self::keptOnEveryNode($workload, $nodes, $image) || self::keptOnAnyNode($workload, $nodes->first(), $image));
    }

    /**
     * Whether every server ran the image and the image cleanup keeps it there (decision 0027). Then the
     * deploy starts it without a pull, also when the registry no longer has it.
     *
     * @param  Collection<int, Node>  $nodes
     */
    private static function keptOnEveryNode(NodeWorkload $workload, Collection $nodes, string $image): bool
    {
        return $nodes->every(fn (Node $node): bool => $node->operations()
            ->where('node_workload_id', $workload->id)
            ->whereIn('command_type', ['workload.deploy.v1', 'workload.move.v1'])
            ->where('status', NodeOperationStatus::SUCCEEDED)
            ->whereHas('revision', fn ($revision) => $revision->where('image', $image))
            ->exists()
            && in_array($image, CleanupNodeImages::keepList($node), true));
    }

    /** The successful build that made the image of the revision, with a source that can be built again. */
    private function buildOf(NodeWorkload $workload, NodeWorkloadRevision $revision): NodeBuild
    {
        $build = $workload->builds()
            ->where('status', NodeBuildStatus::SUCCEEDED)
            ->when(
                NodeImages::isLocalImage($revision->image),
                fn ($builds) => $builds->where('image', $revision->image),
                fn ($builds) => $builds->where('image_digest', Str::after($revision->image, '@')),
            )
            ->latest('id')
            ->first();
        if ($build === null || ($build->source_type->usesGit() && blank($build->commit_sha))) {
            throw new RuntimeException('No server keeps the image of this revision anymore, and Coolify cannot build it again because it does not know the commit that it was built from.');
        }

        return $build;
    }

    /**
     * Whether an online Node of the cluster keeps the local image, so that the other Nodes can copy
     * it (decision 0030): the build Node or a Node that ran it, when the image cleanup keeps it there.
     */
    private static function keptOnAnyNode(NodeWorkload $workload, Node $node, string $image): bool
    {
        $nodeIds = $workload->builds()->where('status', NodeBuildStatus::SUCCEEDED)->where('image', $image)->pluck('node_id')
            ->merge(NodeOperation::query()
                ->where('node_workload_id', $workload->id)
                ->whereIn('command_type', ['workload.deploy.v1', 'workload.move.v1'])
                ->where('status', NodeOperationStatus::SUCCEEDED)
                ->whereHas('revision', fn ($revision) => $revision->where('image', $image))
                ->pluck('node_id'))
            ->filter()
            ->unique();

        return Node::query()
            ->whereKey($nodeIds->all())
            ->where('team_id', $workload->team_id)
            ->where('node_cluster_id', $node->node_cluster_id)
            ->get()
            ->contains(fn (Node $source): bool => $source->canReceiveNetworkCommands()
                && $source->supportsCapability(NodeImages::SERVE_CAPABILITY) === true
                && in_array($image, CleanupNodeImages::keepList($source), true));
    }
}
