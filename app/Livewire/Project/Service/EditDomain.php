<?php

namespace App\Livewire\Project\Service;

use App\Models\ServiceApplication;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class EditDomain extends Component
{
    use AuthorizesRequests;

    public $applicationId;

    public ServiceApplication $application;

    public $domainConflicts = [];

    public $showDomainConflictModal = false;

    public $forceSaveDomains = false;

    public $showPortWarningModal = false;

    public $forceRemovePort = false;

    public $requiredPort = null;

    #[Validate]
    public ?string $fqdn = null;

    protected function rules(): array
    {
        return [
            'fqdn' => ValidationPatterns::applicationDomainRules(),
        ];
    }

    public function mount()
    {
        $this->application = ServiceApplication::ownedByCurrentTeam()->findOrFail($this->applicationId);
        $this->authorize('view', $this->application);
        $this->requiredPort = $this->application->getRequiredPort();
        $this->syncData();
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();

            // Sync to model
            $this->application->setEditableUrls($this->fqdn);

            $this->application->save();
        } else {
            // Sync from model
            $this->fqdn = $this->application->url;
        }
    }

    public function confirmDomainUsage()
    {
        $this->forceSaveDomains = true;
        $this->showDomainConflictModal = false;
        $this->submit();
    }

    public function confirmRemovePort()
    {
        $this->forceRemovePort = true;
        $this->showPortWarningModal = false;
        $this->submit();
    }

    public function cancelRemovePort()
    {
        $this->showPortWarningModal = false;
        $this->syncData(); // Reset to original FQDN
    }

    public function submit()
    {
        try {
            $persistedApplication = $this->application->fresh();
            $previousEditableUrls = $persistedApplication->url;
            $previousFqdn = $persistedApplication->fqdn;
            $previousPortOverrides = $persistedApplication->domain_port_overrides;
            $this->authorize('update', $this->application);
            $this->validate();

            $this->fqdn = ValidationPatterns::normalizeApplicationDomains($this->fqdn);
            $warning = sslipDomainWarning($this->fqdn);
            if ($warning) {
                $this->dispatch('warning', __('warning.sslipdomain'));
            }
            // Sync to model for domain conflict check (without validation)
            $this->application->setEditableUrls($this->fqdn);
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

            // Check for required port
            if (! $this->forceRemovePort) {
                $requiredPort = $this->application->getRequiredPort();

                if ($requiredPort !== null) {
                    foreach (str($this->fqdn)->trim()->explode(',') as $fqdn) {
                        $fqdn = trim((string) $fqdn);
                        if ($fqdn === '') {
                            continue;
                        }

                        if ($this->application->portRequiresConfirmation($fqdn, $requiredPort, $previousEditableUrls)) {
                            $this->requiredPort = $requiredPort;
                            $this->showPortWarningModal = true;
                            $this->application->fqdn = $previousFqdn;
                            $this->application->domain_port_overrides = $previousPortOverrides;

                            return;
                        }
                    }
                }
            } else {
                // Reset the force flag after using it
                $this->forceRemovePort = false;
            }

            $this->validate();
            $this->application->save();
            $this->application->refresh();
            $this->syncData();
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
                $this->syncData();
            }

            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.service.edit-domain');
    }
}
