<?php

namespace App\Livewire\Project\Service;

use App\Livewire\Concerns\SynchronizesModelData;
use App\Models\ServiceApplication;
use Livewire\Component;
use Spatie\Url\Url;

class EditDomain extends Component
{
    use SynchronizesModelData;
    public $applicationId;

    public ServiceApplication $application;

    public $domainConflicts = [];

    public $showDomainConflictModal = false;

    public $forceSaveDomains = false;

    public ?string $fqdn = null;

    protected $rules = [
        'fqdn' => 'nullable',
    ];

    public function mount()
    {
        $this->application = ServiceApplication::query()->findOrFail($this->applicationId);
        $this->authorize('view', $this->application);
        $this->syncFromModel();
    }

    protected function getModelBindings(): array
    {
        return [
            'fqdn' => 'application.fqdn',
        ];
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
            $this->authorize('update', $this->application);
            $this->fqdn = str($this->fqdn)->replaceEnd(',', '')->trim()->toString();
            $this->fqdn = str($this->fqdn)->replaceStart(',', '')->trim()->toString();
            $domains = str($this->fqdn)->trim()->explode(',')->map(function ($domain) {
                $domain = trim($domain);
                Url::fromString($domain, ['http', 'https']);

                return str($domain)->lower();
            });
            $this->fqdn = $domains->unique()->implode(',');
            $warning = sslipDomainWarning($this->fqdn);
            if ($warning) {
                $this->dispatch('warning', __('warning.sslipdomain'));
            }
            // Sync to model for domain conflict check
            $this->syncToModel();
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
            $this->application->refresh();
            $this->syncFromModel();
            updateCompose($this->application);
            if (str($this->application->fqdn)->contains(',')) {
                $this->dispatch('warning', 'Some services do not support multiple domains, which can lead to problems and is NOT RECOMMENDED.<br><br>Only use multiple domains if you know what you are doing.');
            }
            $this->application->service->parse();
            $this->dispatch('refresh');
            $this->dispatch('refreshServices');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            $originalFqdn = $this->application->getOriginal('fqdn');
            if ($originalFqdn !== $this->application->fqdn) {
                $this->application->fqdn = $originalFqdn;
                $this->syncFromModel();
            }

            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.service.edit-domain');
    }
}
