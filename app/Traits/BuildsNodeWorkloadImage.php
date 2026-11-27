<?php

namespace App\Traits;

use App\Actions\Node\Build\IssueNodeGitCredential;
use App\Actions\Node\Build\PublishBuiltRevision;
use App\Actions\Node\Build\ResolveGitCommit;
use App\Actions\Node\Build\RunImageBuild;
use App\Actions\Node\Build\StartQueuedNodeBuilds;
use App\Actions\Node\Build\StartWaitingNodeBuild;
use App\Actions\Node\NotifyNodeDeployment;
use App\Enums\NodeBuildStatus;
use App\Models\NodeBuild;
use RuntimeException;
use Throwable;

/**
 * The steps that every Git build job shares: issue the Git credential of a
 * private repository, resolve the commit, wait in the build queue of the
 * cluster for a free builder slot, build, then publish and deploy the revision. A job only
 * says what the builder must do with the source.
 */
trait BuildsNodeWorkloadImage
{
    /**
     * One attempt. A build that waits for a free builder ends its job and holds no worker;
     * StartQueuedNodeBuilds dispatches it again when it claims a slot (decision 0035).
     */
    public int $tries = 1;

    public int $timeout = 2400;

    public bool $failOnTimeout = true;

    public function __construct(public int $buildId) {}

    /**
     * What the builder does with the source: the `source_type` and its options.
     *
     * @return array{source_type: string, dockerfile_path?: string, dockerfile_content?: string}
     */
    abstract protected function source(NodeBuild $build): array;

    public function handle(): void
    {
        $build = NodeBuild::query()->find($this->buildId);
        if ($build === null || $build->is_waiting) {
            return;
        }
        if ($build->status !== NodeBuildStatus::QUEUED) {
            // Cancelled before it started: the next waiting build of the workload can start.
            StartWaitingNodeBuild::run($build->node_workload_id);

            return;
        }

        try {
            // A private repository gets a new credential for each attempt; it is never stored.
            $credential = null;
            if ($build->commit_sha === null && $build->source_type->usesGit()) {
                $credential = IssueNodeGitCredential::run($build->workload);
                $build->update(['commit_sha' => ResolveGitCommit::run($build->git_repository, $build->git_branch, $credential)]);
            }
            // A build that StartQueuedNodeBuilds claimed has its builder; another one joins the queue of its cluster.
            $node = $build->node;
            if ($node === null) {
                $clusterId = $build->node_cluster_id ?? collect($build->workload->clusterIds())->min()
                    ?? throw new RuntimeException('The application is not placed on a cluster server.');
                $node = StartQueuedNodeBuilds::run((int) $clusterId, $build);
                if ($node === null) {
                    $this->logQueuePosition($build->refresh());

                    return;
                }
            }
            try {
                $digest = RunImageBuild::run($build, $node, $this->source($build), $credential ?? IssueNodeGitCredential::run($build->workload));
            } catch (RuntimeException $exception) {
                // The second guard: Sentinel runs its limit of builds already. The build waits again.
                if (str_contains($exception->getMessage(), NodeBuild::SENTINEL_BUSY) && $build->refresh()->status !== NodeBuildStatus::CANCELLED) {
                    StartQueuedNodeBuilds::requeue($build);
                    $this->logLine($build, "Server {$node->name} refused the build because it runs its limit of builds. The build waits for a free builder server.");

                    return;
                }
                throw $exception;
            }
            // A cancel that arrives while the image is pushed wins: the image is not deployed.
            if ($build->refresh()->status === NodeBuildStatus::CANCELLED) {
                $this->startNext($build);

                return;
            }
            PublishBuiltRevision::run($build, $digest);
            NodeBuild::query()
                ->whereKey($build->id)
                ->whereIn('status', NodeBuildStatus::activeValues())
                ->update(['status' => NodeBuildStatus::SUCCEEDED, 'finished_at' => now(), 'error' => null]);
        } catch (Throwable $exception) {
            $this->markFailed($build, $exception->getMessage());
        }
        $this->startNext($build);
    }

    public function failed(?Throwable $exception): void
    {
        $build = NodeBuild::query()->find($this->buildId);
        if ($build !== null) {
            $this->markFailed($build, $exception?->getMessage() ?? 'The build job failed.');
            $this->startNext($build);
        }
    }

    /** The next waiting build of the workload, then the free slots of the cluster. */
    private function startNext(NodeBuild $build): void
    {
        StartWaitingNodeBuild::run($build->node_workload_id);
        $clusterId = $build->refresh()->node_cluster_id ?? $build->node?->node_cluster_id;
        if ($clusterId !== null) {
            StartQueuedNodeBuilds::run((int) $clusterId);
        }
    }

    /** The position of a build that waits for a free builder, in its build log. */
    private function logQueuePosition(NodeBuild $build): void
    {
        $position = $build->builderQueuePosition();
        if ($position !== null) {
            $this->logLine($build, "Waiting for a free builder server (position {$position['position']} of {$position['total']}).");
        }
    }

    private function logLine(NodeBuild $build, string $line): void
    {
        $build->logLines()->create(['logged_at' => now(), 'line' => $line]);
    }

    /** A cancelled build keeps its status. Only the change to failed sends the notification, so a retry does not send it again. */
    private function markFailed(NodeBuild $build, string $message): void
    {
        $changed = NodeBuild::query()
            ->whereKey($build->id)
            ->whereIn('status', NodeBuildStatus::activeValues())
            ->update(['status' => NodeBuildStatus::FAILED, 'finished_at' => now(), 'error' => mb_substr($message, 0, 2000)]);
        if ($changed > 0) {
            NotifyNodeDeployment::forFailedBuild($build);
        }
    }
}
