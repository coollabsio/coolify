<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ResourceCard extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public ServiceApplication|ServiceDatabase $resource;

    public array $parameters = [];

    public function getListeners(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        $team = $user->currentTeam();
        if (! $team) {
            return [];
        }

        return [
            "echo-private:team.{$team->id},ServiceChecked" => 'refreshResource',
        ];
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
