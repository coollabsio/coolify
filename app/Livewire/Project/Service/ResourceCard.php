<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ResourceCard extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public Service $service;

    public ServiceApplication|ServiceDatabase $resource;

    public array $parameters = [];

    public function getListeners(): array
    {
        return $this->teamChannelListeners([
            'ServiceChecked' => 'refreshResource',
        ]);
    }

    public function refreshResource(): void
    {
        $this->resource->refresh();
    }

    public function restart(): void
    {
        try {
            $this->authorize('update', $this->service);
            $this->resource->restart();
            $message = $this->resource instanceof ServiceApplication
                ? 'Service application restarted successfully.'
                : 'Service database restarted successfully.';
            $this->dispatch('success', $message);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        return view('livewire.project.service.resource-card', [
            'isApplication' => $this->resource instanceof ServiceApplication,
            'isDatabase' => $this->resource instanceof ServiceDatabase,
        ]);
    }
}
