<?php

namespace App\Traits;

use App\Actions\Node\Build\PublishBuiltRevision;
use App\Actions\Node\Build\ResolveGitCommit;
use App\Actions\Node\Build\RunImageBuild;
use App\Actions\Node\Build\SelectBuilderNode;
use App\Enums\NodeBuildStatus;
use App\Models\NodeBuild;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * The steps that every Git build job shares: resolve the commit, wait for a
 * free builder Node, build, then publish and deploy the revision. A job only
 * says what the builder must do with the source.
 */
trait BuildsNodeWorkloadImage
{
    /** Waiting for a free builder releases the job; any exception fails it. */
    public int $maxExceptions = 1;

    public int $timeout = 2400;

    public bool $failOnTimeout = true;

    public function __construct(public int $buildId) {}

    /**
     * What the builder does with the source: the `source_type` and its options.
     *
     * @return array{source_type: string, dockerfile_path?: string}
     */
    abstract protected function source(NodeBuild $build): array;

    public function retryUntil(): Carbon
    {
        return now()->addHour();
    }

    public function handle(): void
    {
        $build = NodeBuild::query()->find($this->buildId);
        if ($build === null || $build->status !== NodeBuildStatus::QUEUED) {
            return;
        }

        try {
            if ($build->commit_sha === null) {
                $build->update(['commit_sha' => ResolveGitCommit::run($build->git_repository, $build->git_branch)]);
            }
            try {
                $node = SelectBuilderNode::run($build->workload);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === NodeBuild::BUILDERS_BUSY) {
                    $this->release(15);

                    return;
                }
                throw $exception;
            }
            $digest = RunImageBuild::run($build, $node, $this->source($build));
            // A cancel that arrives while the image is pushed wins: the image is not deployed.
            if ($build->refresh()->status === NodeBuildStatus::CANCELLED) {
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
    }

    public function failed(?Throwable $exception): void
    {
        $build = NodeBuild::query()->find($this->buildId);
        if ($build !== null) {
            $this->markFailed($build, $exception?->getMessage() ?? 'The build job failed.');
        }
    }

    /** A cancelled build keeps its status. */
    private function markFailed(NodeBuild $build, string $message): void
    {
        NodeBuild::query()
            ->whereKey($build->id)
            ->whereIn('status', NodeBuildStatus::activeValues())
            ->update(['status' => NodeBuildStatus::FAILED, 'finished_at' => now(), 'error' => mb_substr($message, 0, 2000)]);
    }
}
