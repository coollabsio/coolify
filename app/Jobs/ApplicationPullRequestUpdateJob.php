<?php

namespace App\Jobs;

use App\Enums\ProcessStatus;
use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplicationPullRequestUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $build_logs_url;
    public Application $application;
    public ApplicationPreview $preview;
    public string $body;

    public function __construct(
        public string $application_id,
        public int    $pull_request_id,
        public string $deployment_uuid,
        public string $status
    ) {
    }

    public function handle()
    {
        try {
            $this->application = Application::findOrFail($this->application_id);
            $this->preview = ApplicationPreview::findPreviewByApplicationAndPullId($this->application->id, $this->pull_request_id);

            $this->build_logs_url = base_url() . "/project/{$this->application->environment->project->uuid}/{$this->application->environment->name}/application/{$this->application->uuid}/deployment/{$this->deployment_uuid}";

            if ($this->status === ProcessStatus::IN_PROGRESS->value) {
                $this->body = "The preview deployment is in progress. 🟡\n\n";
            }
            if ($this->status === ProcessStatus::FINISHED->value) {
                $this->body = "The preview deployment is ready. 🟢\n\n";
                if ($this->preview->fqdn) {
                    $this->body .= "[Open Preview]({$this->preview->fqdn}) | ";
                }
            }
            if ($this->status === ProcessStatus::ERROR->value) {
                $this->body = "The preview deployment failed. 🔴\n\n";
            }
            $this->body .= "[Open Build Logs](" . $this->build_logs_url . ")\n\n\n";
            $this->body .= "Last updated at: " . now()->toDateTimeString() . " CET";

            ray('Updating comment', $this->body);
            if ($this->preview->pull_request_issue_comment_id) {
                $this->update_comment();
            } else {
                $this->create_comment();
            }
        } catch (\Exception $e) {
            ray($e);
            throw $e;
        }
    }

    private function update_comment()
    {
        ['data' => $data] = git_api(source: $this->application->source, endpoint: "/repos/{$this->application->git_repository}/issues/comments/{$this->preview->pull_request_issue_comment_id}", method: 'patch', data: [
            'body' => $this->body,
        ], throwError: false);
        if (data_get($data, 'message') === 'Not Found') {
            ray('Comment not found. Creating new one.');
            $this->create_comment();
        }
    }

    private function create_comment()
    {
        ['data' => $data] = git_api(source: $this->application->source, endpoint: "/repos/{$this->application->git_repository}/issues/{$this->pull_request_id}/comments", method: 'post', data: [
            'body' => $this->body,
        ]);
        $this->preview->pull_request_issue_comment_id = $data['id'];
        $this->preview->save();
    }
}
