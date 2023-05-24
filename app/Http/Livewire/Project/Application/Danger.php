<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Danger extends Component
{
    public Application $application;
    public array $parameters;

    public function mount()
    {
        $this->parameters = get_parameters();
    }
    public function delete()
    {
        $destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();

        instant_remote_process(["docker rm -f {$this->application->uuid}"], $destination->server);
        $this->application->delete();
        return redirect()->route('project.resources', [
            'project_uuid' => $this->parameters['project_uuid'],
            'environment_name' => $this->parameters['environment_name']
        ]);
    }
}
