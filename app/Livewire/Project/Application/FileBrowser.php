<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class FileBrowser extends Component
{
    public Application $application;

    public $parameters;

    public function mount()
    {
        $this->parameters = [
            'project_uuid' => request()->route('project_uuid'),
            'environment_uuid' => request()->route('environment_uuid'),
            'application_uuid' => request()->route('application_uuid'),
        ];
    }

    public function render()
    {
        return view('livewire.project.application.file-browser');
    }
}
