<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Status extends Component
{
    public Service $service;

    public ?string $selectedResourceUuid = null;

    public function mount(): void
    {
        $this->selectedResourceUuid = request()->route('stack_service_uuid');
    }

    public function getListeners(): array
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceStatusChanged" => 'refreshStatus',
            "echo-private:team.{$teamId},ServiceChecked" => 'refreshStatus',
        ];
    }

    public function refreshStatus(): void
    {
        $this->service->refresh()->load(['applications', 'databases']);
    }

    public function render(): View
    {
        $selectedResource = $this->selectedResourceUuid
            ? $this->service->applications->firstWhere('uuid', $this->selectedResourceUuid)
                ?? $this->service->databases->firstWhere('uuid', $this->selectedResourceUuid)
            : null;

        return view('livewire.project.service.status', compact('selectedResource'));
    }
}
