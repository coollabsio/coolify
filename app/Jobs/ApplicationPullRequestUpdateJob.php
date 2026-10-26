<?php

namespace App\Jobs;

use App\Enums\ProcessStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
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

            $this->body = $this->commentBody($serviceName);
            if ($this->preview->pull_request_issue_comment_id) {
                $this->update_comment();
            } else {
                $this->create_comment();
            }
        } catch (\Throwable $e) {
            return $e;
        }
    }

    private function commentBody(string $serviceName): string
    {
        $body = match ($this->status) {
            ProcessStatus::QUEUED => "The preview deployment for **{$serviceName}** is queued. ⏳\n\n",
            ProcessStatus::IN_PROGRESS => "The preview deployment for **{$serviceName}** is in progress. 🟡\n\n",
            ProcessStatus::FINISHED => "The preview deployment for **{$serviceName}** is ready. 🟢\n\n".$this->getPreviewLinks(),
            ProcessStatus::ERROR => "The preview deployment for **{$serviceName}** failed. 🔴\n\n",
            ProcessStatus::KILLED => "The preview deployment for **{$serviceName}** was killed. ⚫\n\n",
            ProcessStatus::CANCELLED => "The preview deployment for **{$serviceName}** was cancelled. 🚫\n\n",
            ProcessStatus::CLOSED => '', // Already handled in handle(), but included for completeness
        };
        $this->build_logs_url = base_url()."/project/{$this->application->environment->project->uuid}/environment/{$this->application->environment->uuid}/application/{$this->application->uuid}/deployment/{$this->deployment_uuid}";
        $application_logs_url = base_url()."/project/{$this->application->environment->project->uuid}/environment/{$this->application->environment->uuid}/application/{$this->application->uuid}/logs";

        $body .= $this->imageScanLine();
        $body .= '[Open Build Logs]('.$this->build_logs_url.') | [Open Application Logs]('.$application_logs_url.")\n\n\n";
        $body .= 'Last updated at: '.now()->toDateTimeString().' CET';

        return $body;
    }

    /**
     * "Image scan: passed" or "Image scan: blocked" with a link to the deployment. PR comments can be
     * public, so it never shows counts or vulnerability IDs. Empty when the gate did not scan.
     */
    private function imageScanLine(): string
    {
        if (! $this->deployment_uuid) {
            return '';
        }

        $deployment = ApplicationDeploymentQueue::query()
            ->where('deployment_uuid', $this->deployment_uuid)
            ->where('application_id', $this->application->id)
            ->first(['id', 'scan_gate_result']);
        if (! $deployment || ! is_array($deployment->scan_gate_result)) {
            return '';
        }

        if ($deployment->isImageScanBlocked()) {
            return 'Image scan: blocked. [Open in Coolify]('.$this->build_logs_url.")\n\n";
        }
        if (data_get($deployment->scan_gate_result, 'scan_ran') === true) {
            return 'Image scan: passed. [Open in Coolify]('.$this->build_logs_url.")\n\n";
        }

        return '';
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

    private function getPreviewLinks(): string
    {
        if ($this->application->build_pack === 'dockercompose') {
            $dockerComposeDomains = json_decode($this->preview->docker_compose_domains, true) ?? [];
            $links = [];

            foreach ($dockerComposeDomains as $serviceName => $config) {
                $domain = data_get($config, 'domain');
                if (! empty($domain)) {
                    $firstDomain = str($domain)->explode(',')->first();
                    $firstDomain = trim($firstDomain);
                    if (! empty($firstDomain)) {
                        $links[] = "[Open {$serviceName}]({$firstDomain})";
                    }
                }
            }

            return ! empty($links) ? implode(' | ', $links).' | ' : '';
        }

        return $this->preview->fqdn ? "[Open Preview]({$this->preview->fqdn}) | " : '';
    }
}
