<?php

namespace App\Actions\Node;

use App\Actions\Node\Build\RunImageBuild;
use App\Actions\Node\Build\StartNodeBuild;
use App\Enums\NodeBuildStatus;
use App\Jobs\DeployNodeWorkloadJob;
use App\Models\Node;
use App\Models\NodeBuild;
use App\Models\NodeOperation;
use App\Models\NodeRegistry;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\User;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Rolls a cluster application back to the image of an earlier revision (decision 0017). The
 * rollback adds a new latest revision with that image and the current settings, environment
 * variables, and secrets of the workload, and deploys it like Deploy does, so rolling deploys
 * apply. When the cluster registry no longer has the image, the build of that image runs again
 * from the same commit, and its job deploys the result.
 */
class RollbackNodeWorkload
{
    use AsAction;

    /**
     * @return array{operation: ?NodeOperation, build: ?NodeBuild}
     */
    public function handle(NodeWorkload $workload, NodeWorkloadRevision $revision, ?User $user): array
    {
        if ($revision->node_workload_id !== $workload->id) {
            throw new RuntimeException('This revision does not belong to this application.');
        }
        // An older database image can fail to read data that a newer one wrote, and volume data is not rolled back.
        if ($workload->isDatabase()) {
            throw new RuntimeException('A database cannot be rolled back. Restore a backup instead.');
        }
        $current = $workload->revisions()->latest('id')->firstOrFail();
        if ($revision->image === $current->image) {
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

        if ($this->registryHasImage($workload, $nodes->first(), $revision->image) === false) {
            return ['operation' => null, 'build' => StartNodeBuild::run($workload, $user, rebuildOf: $this->buildOf($workload, $revision))];
        }

        $rollback = PrepareNodeWorkloadRevision::run($workload, $revision->image);
        $operation = null;
        foreach ($nodes as $node) {
            // The image is pinned by digest, or is the tag that the earlier revision ran; a pull of a newer tag would not roll back.
            $deployment = CreateDeploymentOperation::run($node, $rollback, $user, 'missing');
            if ($deployment['created']) {
                DeployNodeWorkloadJob::dispatch($deployment['operation']->id);
                $operation ??= $deployment['operation'];
            }
        }
        if ($operation === null) {
            throw new RuntimeException('This application already has an active operation. The rollback revision deploys when it finishes, or deploy again then.');
        }

        return ['operation' => $operation, 'build' => null];
    }

    /** The successful build that made the image of the revision, with a source that can be built again. */
    private function buildOf(NodeWorkload $workload, NodeWorkloadRevision $revision): NodeBuild
    {
        $digest = Str::after($revision->image, '@');
        $build = $workload->builds()
            ->where('status', NodeBuildStatus::SUCCEEDED)
            ->where('image_digest', $digest)
            ->latest('id')
            ->first();
        if ($build === null || ($build->source_type->usesGit() && blank($build->commit_sha))) {
            throw new RuntimeException('The image of this revision is no longer in the cluster registry, and Coolify cannot build it again because it does not know the commit that it was built from.');
        }

        return $build;
    }

    /**
     * Whether the cluster registry still has the image. Null when the image is not in the cluster
     * registry or the registry cannot tell; then the deploy pulls it and fails with the pull error.
     */
    private function registryHasImage(NodeWorkload $workload, Node $node, string $image): ?bool
    {
        if (! str_starts_with($image, NodeRegistry::HOST.'/'.$workload->uuid.'@sha256:')) {
            return null;
        }
        $registry = $node->cluster?->registry;
        // A cluster without a registry Node (one Node left, or a registry that moved away) has no pushed image.
        if ($registry?->node === null) {
            return false;
        }
        if (! $registry->node->canReceiveNetworkCommands() || $registry->node->supportsCapability(NodeRegistry::INSPECT_CAPABILITY) !== true) {
            return null;
        }
        try {
            $result = RunImageBuild::flux('registry.inspect', ['server_id' => $registry->node->uuid, 'command_id' => (string) Str::uuid()], 60);
        } catch (Throwable) {
            return null;
        }
        if (! data_get($result, 'registry_running') || ! data_get($result, 'healthy')) {
            return null;
        }
        $digest = Str::after($image, '@');
        $found = collect((array) data_get($result, 'repositories', []))
            ->where('name', $workload->uuid)
            ->flatMap(fn ($repository): array => (array) data_get($repository, 'tags', []))
            ->contains(fn ($tag): bool => data_get($tag, 'digest') === $digest);

        if ($found) {
            return true;
        }

        return data_get($result, 'repositories_truncated') ? null : false;
    }
}
