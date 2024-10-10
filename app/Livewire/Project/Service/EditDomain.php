<?php

namespace App\Livewire\Project\Service;

use App\Models\ServiceApplication;
use Livewire\Component;

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

    public function updatedApplicationFqdn()
    {
        try {
            $this->application->fqdn = str($this->application->fqdn)->replaceEnd(',', '')->trim();
            $this->application->fqdn = str($this->application->fqdn)->replaceStart(',', '')->trim();
            $this->application->fqdn = str($this->application->fqdn)->trim()->explode(',')->map(function ($domain) {
                return str($domain)->trim()->lower();
            });
            $this->application->fqdn = $this->application->fqdn->unique()->implode(',');
            $this->application->save();
        } catch(\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            check_domain_usage(resource: $this->application);
            $this->validate();
            $this->application->save();
            updateCompose($this->application);
            if (str($this->application->fqdn)->contains(',')) {
                $this->dispatch('warning', 'Some services do not support multiple domains, which can lead to problems and is NOT RECOMMENDED.<br><br>Only use multiple domains if you know what you are doing.');
            } else {
                $this->dispatch('success', 'Service saved.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->application->service->parse();
            $this->dispatch('refresh');
            $this->dispatch('configurationChanged');
        }
    }

    public function render()
    {
        return view('livewire.project.service.edit-domain');
    }
}
