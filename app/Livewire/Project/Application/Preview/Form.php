<?php

namespace App\Livewire\Project\Application\Preview;

use App\Models\Application;
use Livewire\Component;
use Spatie\Url\Url;

class Form extends Component
{
    public Application $application;

    public string $preview_url_template;

    protected $rules = [
        'application.preview_url_template' => 'required',
    ];

    protected $validationAttributes = [
        'application.preview_url_template' => 'preview url template',
    ];

    public function resetToDefault()
    {
        $this->application->preview_url_template = '{{pr_id}}.{{domain}}';
        $this->preview_url_template = $this->application->preview_url_template;
        $this->application->save();
        $this->generate_real_url();
    }

    public function generate_real_url()
    {
        if (data_get($this->application, 'fqdn')) {
            try {
                $firstFqdn = str($this->application->fqdn)->before(',');
                $url = Url::fromString($firstFqdn);
                $host = $url->getHost();
                $this->preview_url_template = str($this->application->preview_url_template)->replace('{{domain}}', $host);
            } catch (\Exception $e) {
                $this->dispatch('error', 'Invalid FQDN.');
            }
        }
    }

    public function mount()
    {
        $this->generate_real_url();
    }

    public function submit()
    {
        $this->validate();
        $this->application->preview_url_template = str_replace(' ', '', $this->application->preview_url_template);
        $this->application->save();
        $this->dispatch('success', 'Preview url template updated.');
        $this->generate_real_url();
    }
}
