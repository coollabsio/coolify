<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Databases extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public array $parameters;

    public function mount()
    {
        $this->parameters = [
            'project_uuid' => $this->application->project()->uuid,
            'environment_uuid' => $this->application->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ];
    }

    public function render()
    {
        return view('livewire.project.application.databases', [
            'databases' => $this->application->serviceDatabase()->get(),
        ]);
    }
}
