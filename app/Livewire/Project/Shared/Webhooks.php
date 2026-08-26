<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\GitlabApp;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

// Refactored ✅
class Webhooks extends Component
{
    use AuthorizesRequests;

    public $resource;

    public ?string $deploywebhook;

    public ?string $githubManualWebhook;

    public ?string $gitlabManualWebhook;

    public ?string $bitbucketManualWebhook;

    public ?string $giteaManualWebhook;

    public ?string $githubManualWebhookSecret = null;

    public ?string $gitlabManualWebhookSecret = null;

    public ?string $bitbucketManualWebhookSecret = null;

    public ?string $giteaManualWebhookSecret = null;

    public ?string $gitlabAppWebhookUrl = null;

    /**
     * One of: unknown, active, missing, error.
     */
    public string $gitlabAppWebhookState = 'unknown';

    public ?string $gitlabAppWebhookMessage = null;

    public function mount()
    {
        $this->deploywebhook = generateDeployWebhook($this->resource);

        if ($this->gitlabSource()) {
            try {
                $this->gitlabAppWebhookUrl = gitlabWebhookUrl($this->gitlabSource());
            } catch (\Throwable $e) {
                $this->gitlabAppWebhookState = 'error';
                $this->gitlabAppWebhookMessage = $e->getMessage();
            }
        }

        if ($this->canViewSecrets()) {
            $this->githubManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_github');
            $this->gitlabManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_gitlab');
            $this->bitbucketManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_bitbucket');
            $this->giteaManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_gitea');
        }

        $this->githubManualWebhook = generateGitManualWebhook($this->resource, 'github');
        $this->gitlabManualWebhook = generateGitManualWebhook($this->resource, 'gitlab');
        $this->bitbucketManualWebhook = generateGitManualWebhook($this->resource, 'bitbucket');
        $this->giteaManualWebhook = generateGitManualWebhook($this->resource, 'gitea');
    }

    public function canViewSecrets(): bool
    {
        return auth()->user()->can('update', $this->resource);
    }

    /**
     * The GitLab App backing this application, when the repository is connected through one.
     */
    public function gitlabSource(): ?GitlabApp
    {
        if (! $this->resource instanceof Application) {
            return null;
        }

        $source = $this->resource->source;

        if (! $source instanceof GitlabApp) {
            return null;
        }

        return blank($this->resource->repository_project_id) ? null : $source;
    }

    /**
     * Ask GitLab whether the project hook still exists. Kept out of mount() so a slow or
     * unreachable GitLab instance never blocks rendering the page.
     */
    public function checkGitlabAppWebhook(): void
    {
        $source = $this->gitlabSource();
        if (! $source) {
            return;
        }

        try {
            $existing = findGitlabProjectWebhook($source, (int) $this->resource->repository_project_id);
            $this->gitlabAppWebhookState = $existing ? 'active' : 'missing';
            $this->gitlabAppWebhookMessage = null;
        } catch (\Throwable $e) {
            $this->gitlabAppWebhookState = 'error';
            $this->gitlabAppWebhookMessage = $e->getMessage();
        }
    }

    public function syncGitlabAppWebhook()
    {
        try {
            $this->authorize('update', $this->resource);

            $source = $this->gitlabSource();
            if (! $source) {
                throw new \RuntimeException('This application is not connected to a GitLab App.');
            }

            $result = syncGitlabProjectWebhook($source, (int) $this->resource->repository_project_id);

            $this->gitlabAppWebhookState = 'active';
            $this->gitlabAppWebhookMessage = null;

            $this->dispatch('success', $result['status'] === 'created'
                ? 'Webhook created in GitLab.'
                : 'Webhook updated in GitLab.');
        } catch (\Throwable $e) {
            $this->gitlabAppWebhookState = 'error';
            $this->gitlabAppWebhookMessage = $e->getMessage();

            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->resource);
            $this->resource->update([
                'manual_webhook_secret_github' => $this->githubManualWebhookSecret,
                'manual_webhook_secret_gitlab' => $this->gitlabManualWebhookSecret,
                'manual_webhook_secret_bitbucket' => $this->bitbucketManualWebhookSecret,
                'manual_webhook_secret_gitea' => $this->giteaManualWebhookSecret,
            ]);
            $this->dispatch('success', 'Webhook secrets saved.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
