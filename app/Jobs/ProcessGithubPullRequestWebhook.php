<?php

namespace App\Jobs;

use App\Actions\Application\CleanupPreviewDeployment;
use App\Actions\Application\EnsurePullRequestPreview;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProcessStatus;
use App\Http\Controllers\Webhook\Concerns\DetectsSkipDeployCommits;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use App\Services\DeployTriggers\ClosedPullRequests;
use App\Services\DeployTriggers\PullRequestContext;
use App\Services\DeployTriggers\PushContext;
use App\Services\DeployTriggers\PushTriggerRouter;
use App\Services\DeployTriggers\TriggerRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Url\Url;
use Throwable;

class ProcessGithubPullRequestWebhook implements ShouldBeEncrypted, ShouldQueue
{
    use DetectsSkipDeployCommits;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public int $applicationId,
        public ?int $githubAppId,
        public string $action,
        public int $pullRequestId,
        public string $pullRequestHtmlUrl,
        public ?string $pullRequestTitle,
        public ?string $beforeSha,
        public ?string $afterSha,
        public string $commitSha,
        public string $fullName,
        public bool $isForkPullRequest = false,
    ) {
        $this->onQueue(webhooks_queue());
    }

    public function handle(): void
    {
        $application = Application::find($this->applicationId);
        if (! $application) {
            return;
        }

        $githubApp = $this->githubAppId ? GithubApp::find($this->githubAppId) : null;

        if ($this->action === 'closed' || $this->action === 'close') {
            $this->handleClosedAction($application);

            return;
        }

        // A late update of a closed pull request must not deploy it again, only an open or a reopen does.
        if ($this->action === 'opened' || $this->action === 'reopened') {
            ClosedPullRequests::clear($application->id, $this->pullRequestId);
        }

        if ($this->action === 'opened' || $this->action === 'synchronize' || $this->action === 'reopened') {
            $this->handleOpenAction($application, $githubApp);
        }
    }

    private function handleClosedAction(Application $application): void
    {
        $found = ApplicationPreview::where('application_id', $application->id)
            ->where('pull_request_id', $this->pullRequestId)
            ->first();

        if ($found) {
            try {
                $this->dispatchPullRequestClosedUpdate($application, $found);
            } catch (Throwable $e) {
                report($e);
            } finally {
                CleanupPreviewDeployment::run($application, $this->pullRequestId, $found);
            }
        } else {
            // An evaluation of the pull request may still wait to create its first preview.
            ClosedPullRequests::markClosed($application, $this->pullRequestId);
        }
    }

    protected function dispatchPullRequestClosedUpdate(Application $application, ApplicationPreview $preview): void
    {
        ApplicationPullRequestUpdateJob::dispatchSync(
            application: $application,
            preview: $preview,
            status: ProcessStatus::CLOSED
        );
    }

    private function handleOpenAction(Application $application, ?GithubApp $githubApp): void
    {
        if (! $application->isPRDeployable()) {
            return;
        }

        $repository_parts = explode('/', $this->fullName);
        $owner = $repository_parts[0] ?? '';
        $repo = $repository_parts[1] ?? '';
        $headCommitMessage = null;

        if ($this->action === 'opened' || $this->action === 'synchronize' || $this->action === 'reopened') {
            $headCommitMessage = getGithubCommitMessage($githubApp, $owner, $repo, $this->commitSha);
        }

        if (self::shouldSkipDeployAny([$this->pullRequestTitle, $headCommitMessage])) {
            $this->recordSkippedDeployment($application, 'Skipped the preview deployment: the pull request title or the head commit message contains a skip marker ([skip ci] or [skip cd]).');

            return;
        }

        // Fork PRs carry untrusted code, so they deploy only when public previews are on.
        // Same-repository branches can only be pushed by users with write access.
        if ($this->isForkPullRequest && ! $application->settings->is_pr_deployments_public_enabled) {
            $this->recordSkippedDeployment($application, 'Skipped the preview deployment: the pull request comes from a fork and public PR deployments are disabled.');

            return;
        }

        $pullRequest = new PullRequestContext(
            number: $this->pullRequestId,
            targetBranch: (string) $application->git_branch,
            isFork: $this->isForkPullRequest,
            gitType: 'github',
            htmlUrl: $this->pullRequestHtmlUrl,
            title: $this->pullRequestTitle,
        );

        // Get changed files for watch path filtering (null means the full list is unknown)
        $changed_files = null;
        if (! blank($application->watch_paths)) {
            if ($this->action === 'synchronize' && $this->beforeSha && $this->afterSha) {
                $changed_files = getGithubCommitRangeFiles($githubApp, $owner, $repo, $this->beforeSha, $this->afterSha);
            } elseif ($this->action === 'opened' || $this->action === 'reopened') {
                $changed_files = getGithubPullRequestFiles($githubApp, $owner, $repo, $this->pullRequestId);
            }
        }

        if (! $this->isForkPullRequest) {
            $router = PushTriggerRouter::forPush(new PushContext(
                provider: $githubApp ? 'github' : 'github_manual',
                teamId: 0,
                repositoryUrl: rtrim($githubApp?->html_url ?? 'https://github.com', '/').'/'.$this->fullName,
                ref: "refs/pull/{$this->pullRequestId}/head",
                after: $this->commitSha,
                before: $this->beforeSha,
                githubAppId: $githubApp?->id,
                repositoryFullName: $this->fullName,
                payloadChangedFiles: $changed_files,
                pullRequest: $pullRequest,
            ));
            if ($router->route($application) === TriggerRoute::Evaluate) {
                $router->defer($application);
                $router->dispatch();

                return;
            }
        }

        // Apply watch path filtering
        if (! blank($application->watch_paths)) {
            if (is_null($changed_files)) {
                Log::warning('Could not get the full list of changed files from GitHub. Deploying the pull request preview without the watch paths check.', [
                    'application_uuid' => $application->uuid,
                    'pull_request_id' => $this->pullRequestId,
                    'action' => $this->action,
                ]);
            } elseif (! $application->isWatchPathsTriggered(collect($changed_files))) {
                $this->recordSkippedDeployment(
                    $application,
                    'Skipped the preview deployment: no changed file matches the watch paths.',
                    [
                        'Watch paths: '.str($application->watch_paths)->explode("\n")->map(fn (string $path) => trim($path))->filter()->implode(', '),
                        'Changed files ('.count($changed_files).'): '.collect($changed_files)->take(50)->implode(', ').(count($changed_files) > 50 ? ', ...' : ''),
                    ],
                );

                return;
            }
        }

        EnsurePullRequestPreview::run($application, $pullRequest, $this->commitSha);

        // Queue the deployment
        $deployment_uuid = new_public_id();
        queue_application_deployment(
            application: $application,
            pull_request_id: $this->pullRequestId,
            deployment_uuid: $deployment_uuid,
            force_rebuild: false,
            commit: $this->commitSha,
            is_webhook: true,
            git_type: 'github'
        );
    }

    /**
     * The webhook already answered "queued", so a skip must leave a deployment row with the reason.
     * The reason is the message of the row, so the deployment list and the API show it without the logs.
     *
     * @param  list<string>  $details
     */
    private function recordSkippedDeployment(Application $application, string $reason, array $details = []): void
    {
        $deploymentUuid = new_public_id();
        $destination = $application->destination;

        $deployment = ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'deployment_uuid' => $deploymentUuid,
            'deployment_url' => Url::fromString($application->link()."/deployment/{$deploymentUuid}")->getPath(),
            'server_id' => $destination?->server?->id,
            'server_name' => $destination?->server?->name,
            'destination_id' => $destination?->id,
            'pull_request_id' => $this->pullRequestId,
            'commit' => $this->commitSha,
            'commit_message' => $reason,
            'status' => ApplicationDeploymentStatus::SKIPPED->value,
            'is_webhook' => true,
            'git_type' => 'github',
            'finished_at' => now(),
        ]);

        foreach ([$reason, ...$details] as $line) {
            $deployment->addLogEntry($line);
        }
    }
}
