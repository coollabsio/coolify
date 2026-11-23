<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ServerStatusBadge extends Component
{
    use ListensToTeamChannel;

    public Application $application;

    public function getListeners(): array
    {
        return $this->teamChannelListeners([
            'ServiceStatusChanged' => 'refreshStatus',
            'ServiceChecked' => 'refreshStatus',
        ]);
    }

    public function refreshStatus(): void
    {
        $this->application->refresh();
    }

    public function render(): View
    {
        return view('livewire.project.application.server-status-badge');
    }
}
