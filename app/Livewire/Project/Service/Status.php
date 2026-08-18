<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Status extends Component
{
    public Service $service;

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
        return view('livewire.project.service.status');
    }
}
