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
            } elseif ($this->status === ProcessStatus::IN_PROGRESS) {
                $this->body = "The preview deployment is in progress. 🟡\n\n";
            } elseif ($this->status === ProcessStatus::FINISHED) {
                $this->body = "The preview deployment is ready. 🟢\n\n";
                if ($this->preview->fqdn) {
                    $this->body .= "[Open Preview]({$this->preview->fqdn}) | ";
                }
            } elseif ($this->status === ProcessStatus::ERROR) {
                $this->body = "The preview deployment failed. 🔴\n\n";
            }
            $this->build_logs_url = base_url()."/project/{$this->application->environment->project->uuid}/{$this->application->environment->name}/application/{$this->application->uuid}/deployment/{$this->deployment_uuid}";

            $this->body .= '[Open Build Logs]('.$this->build_logs_url.")\n\n\n";
            $this->body .= 'Last updated at: '.now()->toDateTimeString().' CET';
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
