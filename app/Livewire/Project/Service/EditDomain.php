<?php

namespace App\Livewire\Project\Service;

use App\Models\ServiceApplication;
use Livewire\Component;
use Spatie\Url\Url;

class EditDomain extends Component
{
    public $applicationId;

    public ServiceApplication $application;

    protected $rules = [
        'application.fqdn' => 'nullable',
        'application.required_fqdn' => 'required|boolean',
    ];

    public function mount()
    {
        $this->application = ServiceApplication::find($this->applicationId);
    }
    public function submit()
    {
        try {
            $this->application->fqdn = str($this->application->fqdn)->replaceEnd(',', '')->trim();
            $this->application->fqdn = str($this->application->fqdn)->replaceStart(',', '')->trim();
            $this->application->fqdn = str($this->application->fqdn)->trim()->explode(',')->map(function ($domain) {
                Url::fromString($domain, ['http', 'https']);
                return str($domain)->trim()->lower();
            });
            $this->application->fqdn = $this->application->fqdn->unique()->implode(',');
            check_domain_usage(resource: $this->application);
            $this->validate();
            $this->application->save();
            updateCompose($this->application);
            if (str($this->application->fqdn)->contains(',')) {
                $this->dispatch('warning', 'Some services do not support multiple domains, which can lead to problems and is NOT RECOMMENDED.<br><br>Only use multiple domains if you know what you are doing.');
            } else {
                $this->dispatch('success', 'Service saved.');
            }
            $this->application->service->parse();
            $this->dispatch('refresh');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            $originalFqdn = $this->application->getOriginal('fqdn');
            if ($originalFqdn !== $this->application->fqdn) {
                $this->application->fqdn = $originalFqdn;
            }
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.service.edit-domain');
    }
}
