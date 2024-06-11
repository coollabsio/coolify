<?php

namespace App\Livewire\Project\Service;

use App\Models\ServiceApplication;
use Livewire\Component;

class ServiceApplicationView extends Component
{
    public ServiceApplication $application;

    public $parameters;

    protected $rules = [
        'application.human_name' => 'nullable',
        'application.description' => 'nullable',
        'application.fqdn' => 'nullable',
        'application.image' => 'required',
        'application.exclude_from_status' => 'required|boolean',
        'application.required_fqdn' => 'required|boolean',
        'application.is_log_drain_enabled' => 'nullable|boolean',
        'application.is_gzip_enabled' => 'nullable|boolean',
        'application.is_stripprefix_enabled' => 'nullable|boolean',
    ];

    public function render()
    {
        return view('livewire.project.service.service-application-view');
    }

    public function updatedApplicationFqdn()
    {
        $this->application->fqdn = str($this->application->fqdn)->replaceEnd(',', '')->trim();
        $this->application->fqdn = str($this->application->fqdn)->replaceStart(',', '')->trim();
        $this->application->fqdn = str($this->application->fqdn)->trim()->explode(',')->map(function ($domain) {
            return str($domain)->trim()->lower();
        });
        $this->application->fqdn = $this->application->fqdn->unique()->implode(',');
        $this->application->save();
    }

    public function instantSave()
    {
        $this->submit();
    }

    public function instantSaveAdvanced()
    {
        if (! $this->application->service->destination->server->isLogDrainEnabled()) {
            $this->application->is_log_drain_enabled = false;
            $this->dispatch('error', 'Log drain is not enabled on the server. Please enable it first.');

            return;
        }
        $this->application->save();
        $this->dispatch('success', 'You need to restart the service for the changes to take effect.');
    }

    public function delete()
    {
        try {
            $this->application->delete();
            $this->dispatch('success', 'Application deleted.');

            return redirect()->route('project.service.configuration', $this->parameters);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
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
            $this->dispatch('generateDockerCompose');
        }
    }
}
