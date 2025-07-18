<?php

namespace App\Livewire\Project\Shared;

use Livewire\Component;

// Refactored ✅
class Webhooks extends Component
{
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

        $this->githubManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_github');
        $this->githubManualWebhook = generateGitManualWebhook($this->resource, 'github');

        $this->gitlabManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_gitlab');
        $this->gitlabManualWebhook = generateGitManualWebhook($this->resource, 'gitlab');

        $this->bitbucketManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_bitbucket');
        $this->bitbucketManualWebhook = generateGitManualWebhook($this->resource, 'bitbucket');

        $this->giteaManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_gitea');
        $this->giteaManualWebhook = generateGitManualWebhook($this->resource, 'gitea');
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
