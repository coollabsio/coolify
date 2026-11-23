<?php

namespace App\Livewire\Project\Database;

use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Status extends Component
{
    use ListensToTeamChannel;

    public $database;

    public function getListeners(): array
    {
        return $this->teamChannelListeners([
            'ServiceStatusChanged' => 'refreshStatus',
            'ServiceChecked' => 'refreshStatus',
        ]);
    }

    public function refreshStatus(): void
    {
        $this->database->refresh();
    }

    public function render(): View
    {
        return view('livewire.project.database.status', [
            'healthcheckUrl' => route('project.database.healthcheck', [
                'project_uuid' => $this->database->environment->project->uuid,
                'environment_uuid' => $this->database->environment->uuid,
                'database_uuid' => $this->database->uuid,
            ]),
        ]);
    }
}
