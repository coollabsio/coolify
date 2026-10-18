<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Status extends Component
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
        return view('livewire.project.application.status', [
            'healthcheckUrl' => route('project.application.healthcheck', [
                'project_uuid' => $this->application->project()->uuid,
                'environment_uuid' => $this->application->environment->uuid,
                'application_uuid' => $this->application->uuid,
            ]),
        ]);
    }
}
