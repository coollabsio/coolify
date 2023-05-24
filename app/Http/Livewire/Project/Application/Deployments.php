<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Deployments extends Component
{
    public int $application_id;
    public $deployments = [];
    public string $current_url;
    public function mount()
    {
        $this->current_url = url()->current();
    }
    public function reloadDeployments()
    {
        $this->loadDeployments();
    }
    public function loadDeployments()
    {
        $this->deployments = Application::find($this->application_id)->deployments();
    }
}
