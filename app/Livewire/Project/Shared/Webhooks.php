<?php

namespace App\Livewire\Project\Shared;

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

    public function mount()
    {
        $this->deploywebhook = generateDeployWebhook($this->resource);

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
            $this->dispatch('success', 'Secret Saved.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
