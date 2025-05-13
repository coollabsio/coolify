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
            if ($this->status === ProcessStatus::CLOSED) {
                $this->delete_comment();
                return;
            } 

            $this->body = "**Preview of {$this->application->name}**\n\n";
            $this->body .= match ($this->status) {
                ProcessStatus::IN_PROGRESS => "🟡 Deployment in progress",
                ProcessStatus::FINISHED => "🟢 Deployment is ready".($this->preview->fqdn ? " | [Open Preview]({$this->preview->fqdn})" : ''),
                ProcessStatus::ERROR => "🔴 Deployment failed",
                default => '',
            };

            $this->build_logs_url = base_url()."/project/{$this->application->environment->project->uuid}/environment/{$this->application->environment->uuid}/application/{$this->application->uuid}/deployment/{$this->deployment_uuid}";
            $this->body .= " | [Open Build Logs]($this->build_logs_url)\n\n";

            $serverTimezone = $this->application->destination->server->settings->server_timezone ?? 'CET';
            $this->body .= "Last updated at: ".now($serverTimezone)->toDateTimeString()." ($serverTimezone)";

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
        ['data' => $data] = githubApi(source: $this->application->source, endpoint: "/repos/{$this->application->git_repository}/issues/comments/{$this->preview->pull_request_issue_comment_id}", method: 'patch', data: [
            'body' => $this->body,
        ], throwError: false);
        if (data_get($data, 'message') === 'Not Found') {
            $this->create_comment();
        }
    }

    private function create_comment()
    {
        ['data' => $data] = githubApi(source: $this->application->source, endpoint: "/repos/{$this->application->git_repository}/issues/{$this->preview->pull_request_id}/comments", method: 'post', data: [
            'body' => $this->body,
        ]);
        $this->preview->pull_request_issue_comment_id = $data['id'];
        $this->preview->save();
    }

    private function delete_comment()
    {
        githubApi(source: $this->application->source, endpoint: "/repos/{$this->application->git_repository}/issues/comments/{$this->preview->pull_request_issue_comment_id}", method: 'delete');
    }
}
