<?php

namespace App\Http\Livewire\Project\Shared;

use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Danger extends Component
{
    public $resource;
    public array $parameters;
    public string|null $modalId = null;

    public function mount()
    {
        $this->modalId = new Cuid2(7);
        $this->parameters = get_route_parameters();
    }

    public function delete()
    {
        $destination = $this->resource->destination->getMorphClass()::where('id', $this->resource->destination->id)->first();

        instant_remote_process(["docker rm -f {$this->resource->uuid}"], $destination->server);
        $this->resource->delete();
        return redirect()->route('project.resources', [
            'project_uuid' => $this->parameters['project_uuid'],
            'environment_name' => $this->parameters['environment_name']
        ]);
    }
}
