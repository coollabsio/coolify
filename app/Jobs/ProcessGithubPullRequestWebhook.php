<?php

namespace App\Jobs;

use App\Actions\Application\CleanupPreviewDeployment;
use App\Enums\ProcessStatus;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Visus\Cuid2\Cuid2;

class ProcessGithubPullRequestWebhook implements ShouldBeEncrypted, ShouldQueue
{
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
        public ?string $beforeSha,
        public ?string $afterSha,
        public string $commitSha,
        public ?string $authorAssociation,
        public string $fullName,
    ) {
        $this->onQueue('high');
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
            ApplicationPullRequestUpdateJob::dispatchSync(
                application: $application,
                preview: $found,
                status: ProcessStatus::CLOSED
            );

            CleanupPreviewDeployment::run($application, $this->pullRequestId, $found);
        }
    }

    private function handleOpenAction(Application $application, ?GithubApp $githubApp): void
    {
        if (! $application->isPRDeployable()) {
            return;
        }

        // Check if PR deployments from public contributors are restricted
        if (! $application->settings->is_pr_deployments_public_enabled) {
            $trustedAssociations = ['OWNER', 'MEMBER', 'COLLABORATOR', 'CONTRIBUTOR'];
            if (! in_array($this->authorAssociation, $trustedAssociations)) {
                return;
            }
        }

        // Get changed files for watch path filtering
        $changed_files = collect();
        $repository_parts = explode('/', $this->fullName);
        $owner = $repository_parts[0] ?? '';
        $repo = $repository_parts[1] ?? '';

        if ($this->action === 'synchronize' && $this->beforeSha && $this->afterSha) {
            // For synchronize events, get files changed between before and after commits
            $changed_files = collect(getGithubCommitRangeFiles($githubApp, $owner, $repo, $this->beforeSha, $this->afterSha));
        } elseif ($this->action === 'opened' || $this->action === 'reopened') {
            // For opened/reopened events, get all files in the PR
            $changed_files = collect(getGithubPullRequestFiles($githubApp, $owner, $repo, $this->pullRequestId));
        }

        // Apply watch path filtering
        $is_watch_path_triggered = $application->isWatchPathsTriggered($changed_files);
        if (! $is_watch_path_triggered && ! blank($application->watch_paths)) {
            return;
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
        $deployment_uuid = new Cuid2;
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
