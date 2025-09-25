<?php

namespace App\Livewire\Project\Service;

use App\Models\ServiceApplication;
use Livewire\Component;
use Spatie\Url\Url;

class EditDomain extends Component
{
    public $applicationId;

    public ServiceApplication $application;

    public $domainConflicts = [];

    public $showDomainConflictModal = false;

    public $forceSaveDomains = false;

    protected $rules = [
        'application.fqdn' => 'nullable',
        'application.required_fqdn' => 'required|boolean',
    ];

    public function mount()
    {
        $this->application = ServiceApplication::find($this->applicationId);
    }

    public function confirmDomainUsage()
    {
        $this->forceSaveDomains = true;
        $this->showDomainConflictModal = false;
        $this->submit();
    }

    public function submit()
    {
        try {
            $this->application->fqdn = str($this->application->fqdn)->replaceEnd(',', '')->trim();
            $this->application->fqdn = str($this->application->fqdn)->replaceStart(',', '')->trim();
            $this->application->fqdn = str($this->application->fqdn)->trim()->explode(',')->map(function ($domain) {
                $domain = trim($domain);
                Url::fromString($domain, ['http', 'https']);

                return str($domain)->lower();
            });
            $this->application->fqdn = $this->application->fqdn->unique()->implode(',');
            $warning = sslipDomainWarning($this->application->fqdn);
            if ($warning) {
                $this->dispatch('warning', __('warning.sslipdomain'));
            }
            // Check for domain conflicts if not forcing save
            if (! $this->forceSaveDomains) {
                $result = checkDomainUsage(resource: $this->application);
                if ($result['hasConflicts']) {
                    $this->domainConflicts = $result['conflicts'];
                    $this->showDomainConflictModal = true;

                    return;
                }
            } else {
                // Reset the force flag after using it
                $this->forceSaveDomains = false;
            }

            $this->validate();
            $this->application->save();
            updateCompose($this->application);
            if (str($this->application->fqdn)->contains(',')) {
                $this->dispatch('warning', 'Some services do not support multiple domains, which can lead to problems and is NOT RECOMMENDED.<br><br>Only use multiple domains if you know what you are doing.');
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
