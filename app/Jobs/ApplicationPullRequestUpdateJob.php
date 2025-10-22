<?php

namespace App\Jobs;

use App\Enums\ProcessStatus;
use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplicationPullRequestUpdateJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $build_logs_url;

    public string $body;

    public function __construct(
        public Application $application,
        public ApplicationPreview $preview,
        public ProcessStatus $status,
        public ?string $deployment_uuid = null
    ) {
        $this->onQueue('high');
    }

    public function handle()
    {
        try {
            if ($this->application->is_public_repository()) {
                return;
            }

            $serviceName = $this->application->name;

            if ($this->status === ProcessStatus::CLOSED) {
                $this->delete_comment();

                return;
            }

            match ($this->status) {
                ProcessStatus::QUEUED => $this->body = "The preview deployment for **{$serviceName}** is queued. ⏳\n\n",
                ProcessStatus::IN_PROGRESS => $this->body = "The preview deployment for **{$serviceName}** is in progress. 🟡\n\n",
                ProcessStatus::FINISHED => $this->body = "The preview deployment for **{$serviceName}** is ready. 🟢\n\n",
                ProcessStatus::ERROR => $this->body = "The preview deployment for **{$serviceName}** failed. 🔴\n\n",
                ProcessStatus::KILLED => $this->body = "The preview deployment for **{$serviceName}** was killed. ⚫\n\n",
                ProcessStatus::CANCELLED => $this->body = "The preview deployment for **{$serviceName}** was cancelled. 🚫\n\n",
                ProcessStatus::CLOSED => '', // Already handled above, but included for completeness
            };

            if ($this->status === ProcessStatus::FINISHED) {
                $this->append_preview_urls_to_body();
            }
            
            $this->build_logs_url = $this->create_build_log_url();
            $this->body .= '[Open Build Logs](' . $this->build_logs_url . ")\n\n\n";
            $this->body .= 'Last updated at: ' . now()->toDateTimeString() . ' CET';
            if ($this->preview->pull_request_issue_comment_id) {
                $this->update_comment();
            } else {
                $this->create_comment();
            }
        } catch (\Throwable $e) {
            return $e;
        }
    }

    private function update_comment()
    {
        ['data' => $data] = githubApi(
            source: $this->application->source,
            endpoint: "/repos/{$this->application->git_repository}/issues/comments/{$this->preview->pull_request_issue_comment_id}",
            method: 'patch',
            data: [
            'body' => $this->body,
        ],
            throwError: false
        );
        if (data_get($data, 'message') === 'Not Found') {
            $this->create_comment();
        }
    }

    private function create_comment()
    {
        ['data' => $data] = githubApi(
            source: $this->application->source,
            endpoint: "/repos/{$this->application->git_repository}/issues/{$this->preview->pull_request_id}/comments",
            method: 'post',
            data: [
            'body' => $this->body,
        ]
        );
        $this->preview->pull_request_issue_comment_id = $data['id'];
        $this->preview->save();
    }

    private function delete_comment()
    {
        githubApi(
            source: $this->application->source,
            endpoint: "/repos/{$this->application->git_repository}/issues/comments/{$this->preview->pull_request_issue_comment_id}",
            method: 'delete'
        );
    }

    private function create_build_log_url(): string
    {
        $baseUrl = base_url();
        $projectId = $this->application->environment->project->uuid ?? 'unknown';
        $environmentId = $this->application->environment->uuid ?? 'unknown';
        $applicationId = $this->application->uuid ?? 'unknown';
        $deploymentId = $this->deployment_uuid ?? 'unknown';

        return "$baseUrl/project/$projectId/environment/$environmentId/application/$applicationId/deployment/$deploymentId";
    }

    private function append_preview_urls_to_body(): void
    {
        if ($this->application->build_pack === 'dockercompose' && $this->preview->docker_compose_domains) {
            $dockerComposeDomains = json_decode($this->preview->docker_compose_domains ?? '', true);
            foreach ($dockerComposeDomains as $service => $entry) {
                if (($domain = $entry['domain'] ?? null) !== null) {
                    $serviceName = ucfirst($service);
                    $this->body .= "[Open $serviceName]($domain) | ";
                }
            }

            return;
        }

        if ($this->preview->fqdn) {
            $this->body .= "[Open Preview]({$this->preview->fqdn}) | ";
        }
    }
}
