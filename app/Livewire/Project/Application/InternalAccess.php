<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class InternalAccess extends Component
{
    protected $listeners = [
        'applicationNetworkingUpdated' => 'refreshApplicationNetworking',
    ];

    public Application $application;

    public ?string $currentInternalHostname = null;

    public bool $currentInternalHostnameLoaded = false;

    public function refreshApplicationNetworking(): void
    {
        $this->application->refresh();
    }

    public function loadCurrentInternalHostname(): void
    {
        try {
            $containers = getCurrentApplicationContainerStatus(
                $this->application->destination->server,
                $this->application->id,
                0
            );
            $currentContainer = $containers->first(
                fn ($container) => data_get($container, 'State') === 'running'
            ) ?? $containers->first();

            $this->currentInternalHostname = data_get($currentContainer, 'Names');
        } catch (\Throwable) {
            $this->currentInternalHostname = null;
        } finally {
            $this->currentInternalHostnameLoaded = true;
        }
    }

    public function render(): View
    {
        return view('livewire.project.application.internal-access');
    }
}
