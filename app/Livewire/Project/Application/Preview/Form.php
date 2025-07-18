<?php

namespace App\Livewire\Project\Application\Preview;

use App\Models\Application;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Spatie\Url\Url;

class Form extends Component
{
    public Application $application;

    #[Validate('required')]
    public string $previewUrlTemplate;

    public function mount()
    {
        try {
            $this->previewUrlTemplate = $this->application->preview_url_template;
            $this->generateRealUrl();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->resetErrorBag();
            $this->validate();
            $this->application->preview_url_template = str_replace(' ', '', $this->previewUrlTemplate);
            $this->application->save();
            $this->dispatch('success', 'Preview url template updated.');
            $this->generateRealUrl();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function resetToDefault()
    {
        try {
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
