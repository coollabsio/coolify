<?php

namespace App\Jobs;

use App\Actions\Application\CleanupPreviewDeployment;
use App\Enums\ProcessStatus;
use App\Http\Controllers\Webhook\Concerns\DetectsSkipDeployCommits;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
            return;
        }

        // Fork PRs carry untrusted code, so they deploy only when public previews are on.
        // Same-repository branches can only be pushed by users with write access.
        if ($this->isForkPullRequest && ! $application->settings->is_pr_deployments_public_enabled) {
            Log::info('Skipped GitHub pull request preview deployment from a fork because public PR deployments are disabled.', [
                'application_uuid' => $application->uuid,
                'pull_request_id' => $this->pullRequestId,
                'action' => $this->action,
            ]);

            return;
        }

        // Get changed files for watch path filtering (null means the full list is unknown)
        $changed_files = [];

        if ($this->action === 'synchronize' && $this->beforeSha && $this->afterSha) {
            // For synchronize events, get files changed between before and after commits
            $changed_files = getGithubCommitRangeFiles($githubApp, $owner, $repo, $this->beforeSha, $this->afterSha);
        } elseif ($this->action === 'opened' || $this->action === 'reopened') {
            // For opened/reopened events, get all files in the PR
            $changed_files = getGithubPullRequestFiles($githubApp, $owner, $repo, $this->pullRequestId);
        }

        // Apply watch path filtering
        if (! blank($application->watch_paths)) {
            $logContext = [
                'application_uuid' => $application->uuid,
                'pull_request_id' => $this->pullRequestId,
                'action' => $this->action,
            ];

            if (is_null($changed_files)) {
                Log::warning('Could not get the full list of changed files from GitHub. Deploying the pull request preview without the watch paths check.', $logContext);
            } elseif (! $application->isWatchPathsTriggered(collect($changed_files))) {
                Log::info('Skipped GitHub pull request preview deployment because no changed file matches the watch paths.', $logContext + [
                    'changed_files_count' => count($changed_files),
                ]);

                return;
            }
        }

        // Create ApplicationPreview if not exists
        $found = ApplicationPreview::where('application_id', $application->id)
            ->where('pull_request_id', $this->pullRequestId)
            ->first();

        if (! $found) {
            if ($application->build_pack === 'dockercompose') {
                $preview = ApplicationPreview::create([
                    'git_type' => 'github',
                    'application_id' => $application->id,
                    'pull_request_id' => $this->pullRequestId,
                    'pull_request_html_url' => $this->pullRequestHtmlUrl,
                    'docker_compose_domains' => $application->docker_compose_domains,
                ]);
                $preview->generate_preview_fqdn_compose();
            } else {
                $preview = ApplicationPreview::create([
                    'git_type' => 'github',
                    'application_id' => $application->id,
                    'pull_request_id' => $this->pullRequestId,
                    'pull_request_html_url' => $this->pullRequestHtmlUrl,
                ]);
                $preview->generate_preview_fqdn();
            }
        }

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
}
