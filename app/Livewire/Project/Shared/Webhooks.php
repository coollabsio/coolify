<?php

namespace App\Livewire\Project\Shared;

use Livewire\Component;

class Webhooks extends Component
{
    public $resource;
    public ?string $deploywebhook = null;
    public ?string $githubManualWebhook = null;
    public ?string $gitlabManualWebhook = null;
    public ?string $bitbucketManualWebhook = null;
    public ?string $giteaManualWebhook = null;
    protected $rules = [
        'resource.manual_webhook_secret_github' => 'nullable|string',
        'resource.manual_webhook_secret_gitlab' => 'nullable|string',
        'resource.manual_webhook_secret_bitbucket' => 'nullable|string',
        'resource.manual_webhook_secret_gitea' => 'nullable|string',
    ];
    public function saveSecret()
    {
        try {
            $this->validate();
            $this->resource->save();
            $this->dispatch('success','Secret Saved.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
    public function mount()
    {
        $this->deploywebhook = generateDeployWebhook($this->resource);
        $this->githubManualWebhook = generateGitManualWebhook($this->resource, 'github');
        $this->gitlabManualWebhook = generateGitManualWebhook($this->resource, 'gitlab');
        $this->bitbucketManualWebhook = generateGitManualWebhook($this->resource, 'bitbucket');
        $this->giteaManualWebhook = generateGitManualWebhook($this->resource, 'gitea');
    }
    public function render()
    {
        return view('livewire.project.shared.webhooks');
    }
}
