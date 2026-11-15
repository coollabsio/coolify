<?php

namespace App\Jobs;

use App\Enums\NodeBuildStatus;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadDesiredState;
use App\Models\GithubApp;
use App\Models\NodeBuild;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Keeps the comment of a preview on its GitHub pull request up to date for one build (decision
 * 0025): the v4 comment of ApplicationPullRequestUpdateJob. The job checks the build and its deploy,
 * writes the comment when the status changes, and checks again in 15 seconds until the deploy ends.
 * A newer build of the preview starts its own job, and this one stops.
 */
class UpdateNodePreviewCommentJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** One hour of checks. */
    private const MAX_CHECKS = 240;

    public function __construct(public int $previewId, public int $buildId, public ?string $postedStatus = null, public int $check = 0)
    {
        $this->onQueue('high');
    }

    /** Starts the comment updates when the parent reads the repository through a GitHub App. */
    public static function dispatchFor(NodeWorkload $preview, NodeBuild $build): void
    {
        if ($preview->github_app_id !== null && $preview->pull_request_id !== null) {
            self::dispatch($preview->id, $build->id);
        }
    }

    public function handle(): void
    {
        $preview = NodeWorkload::query()->withoutGlobalScopes()->find($this->previewId);
        $githubApp = $preview?->githubApp;
        if ($preview === null || $preview->desired_state === NodeWorkloadDesiredState::REMOVED || ! $githubApp instanceof GithubApp || $githubApp->is_public) {
            return;
        }
        $build = $preview->builds()->latest('id')->first();
        if ($build === null || $build->id !== $this->buildId) {
            return;
        }

        [$status, $final] = $this->status($preview, $build);
        if ($status !== $this->postedStatus) {
            $this->writeComment($preview, $githubApp, $build, $status);
        }
        if (! $final && $this->check < self::MAX_CHECKS) {
            self::dispatch($this->previewId, $this->buildId, $status, $this->check + 1)->delay(now()->addSeconds(15));
        }
    }

    /** @return array{0: 'queued'|'in_progress'|'ready'|'failed'|'cancelled', 1: bool} */
    private function status(NodeWorkload $preview, NodeBuild $build): array
    {
        return match ($build->status) {
            NodeBuildStatus::QUEUED => ['queued', false],
            NodeBuildStatus::RUNNING => ['in_progress', false],
            NodeBuildStatus::FAILED => ['failed', true],
            NodeBuildStatus::CANCELLED => ['cancelled', true],
            NodeBuildStatus::SUCCEEDED => $this->deployStatus($preview, $build),
        };
    }

    /**
     * The deploy of the built revision. A build that changed nothing creates no deploy, so the
     * running revision is the ready one.
     *
     * @return array{0: 'in_progress'|'ready'|'failed', 1: bool}
     */
    private function deployStatus(NodeWorkload $preview, NodeBuild $build): array
    {
        $operation = NodeOperation::query()
            ->where('node_workload_id', $preview->id)
            ->where('node_workload_revision_id', $build->node_workload_revision_id)
            ->whereIn('command_type', NodeOperation::DEPLOYMENT_COMMAND_TYPES)
            ->latest('id')
            ->first();

        return match (true) {
            $operation === null => ['ready', true],
            ! $operation->status->isFinal() => ['in_progress', false],
            $operation->status === NodeOperationStatus::SUCCEEDED => ['ready', true],
            default => ['failed', true],
        };
    }

    private function writeComment(NodeWorkload $preview, GithubApp $githubApp, NodeBuild $build, string $status): void
    {
        $repository = gitRepositorySlug($preview->git_repository);
        $body = $this->body($preview, $build, $status);
        if ($preview->pull_request_comment_id !== null) {
            ['data' => $data] = githubApi($githubApp, "/repos/{$repository}/issues/comments/{$preview->pull_request_comment_id}", 'patch', ['body' => $body], throwError: false);
            if (data_get($data, 'message') !== 'Not Found') {
                return;
            }
        }
        ['data' => $data] = githubApi($githubApp, "/repos/{$repository}/issues/{$preview->pull_request_id}/comments", 'post', ['body' => $body]);
        NodeWorkload::query()->withoutGlobalScopes()->whereKey($preview->id)->update(['pull_request_comment_id' => $data['id']]);
    }

    private function body(NodeWorkload $preview, NodeBuild $build, string $status): string
    {
        $name = $preview->previewOf?->name ?? $preview->name;
        $body = match ($status) {
            'queued' => "The preview deployment for **{$name}** is queued. ⏳\n\n",
            'in_progress' => "The preview deployment for **{$name}** is in progress. 🟡\n\n",
            'ready' => "The preview deployment for **{$name}** is ready. 🟢\n\n",
            'failed' => "The preview deployment for **{$name}** failed. 🔴\n\n",
            'cancelled' => "The preview deployment for **{$name}** was cancelled. 🚫\n\n",
        };
        $parameters = ['project_uuid' => $preview->project->uuid, 'environment_uuid' => $preview->environment->uuid, 'workload_uuid' => $preview->uuid];
        $links = [];
        $domain = $preview->domain_routes[0] ?? null;
        if ($status === 'ready' && $domain !== null) {
            $links[] = "[Open Preview]({$domain['scheme']}://{$domain['host']}".($domain['path'] ?? '').')';
        }
        $links[] = '[Open Build Logs]('.base_url().route('project.cluster-application.build.show', [...$parameters, 'build_uuid' => $build->uuid], false).')';
        $links[] = '[Open Application Logs]('.base_url().route('project.cluster-application.logs', $parameters, false).')';

        return $body.implode(' | ', $links)."\n\n\nLast updated at: ".now()->toDateTimeString().' UTC';
    }
}
