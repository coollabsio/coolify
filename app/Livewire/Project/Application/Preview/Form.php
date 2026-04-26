<?php

namespace App\Livewire\Project\Application\Preview;

use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Spatie\Url\Url;

class Form extends Component
{
    use AuthorizesRequests;

    public Application $application;

    #[Validate('required')]
    public string $previewUrlTemplate;

    #[Validate(['integer', 'min:0', 'max:1000'])]
    public int $maxPreviewDeployments = 0;

    public function mount()
    {
        try {
            $this->previewUrlTemplate = $this->application->preview_url_template;
            $this->maxPreviewDeployments = (int) ($this->application->settings->max_preview_deployments ?? 0);
            $this->generateRealUrl();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->application);
            $this->resetErrorBag();
            $this->validate();
            $this->application->preview_url_template = str_replace(' ', '', $this->previewUrlTemplate);
            $this->application->save();
            $this->application->settings->max_preview_deployments = $this->maxPreviewDeployments;
            $this->application->settings->save();
            $this->dispatch('success', 'Preview settings updated.');
            $this->generateRealUrl();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function resetToDefault()
    {
        try {
            $this->authorize('update', $this->application);
            $this->application->preview_url_template = '{{pr_id}}.{{domain}}';
            $this->previewUrlTemplate = $this->application->preview_url_template;
            $this->application->save();
            $this->generateRealUrl();
            $this->dispatch('success', 'Preview url template updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function generateRealUrl()
    {
        if (data_get($this->application, 'fqdn')) {
            $firstFqdn = str($this->application->fqdn)->before(',');
            $url = Url::fromString($firstFqdn);
            $host = $url->getHost();
            $this->previewUrlTemplate = str($this->application->preview_url_template)->replace('{{domain}}', $host);
        }
    }
}
