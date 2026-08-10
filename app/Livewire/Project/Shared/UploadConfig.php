<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class UploadConfig extends Component
{
    use AuthorizesRequests;

    public $config;

    public $applicationId;

    public function mount()
    {
        if (isDev()) {
            $this->config = '{
    "build_pack": "nixpacks",
    "base_directory": "/nodejs",
    "publish_directory": "/",
    "ports_exposes": "3000",
    "settings": {
        "is_static": false
    }
}';
        }
    }

    public function uploadConfig()
    {
        try {
            $application = Application::ownedByCurrentTeam()->findOrFail($this->applicationId);
            $this->authorize('update', $application);
            $application->setConfig($this->config);
            $this->dispatch('success', 'Application settings updated');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.upload-config');
    }
}
