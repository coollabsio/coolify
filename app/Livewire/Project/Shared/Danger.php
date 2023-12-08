<?php

namespace App\Livewire\Project\Shared;

use App\Jobs\DeleteResourceJob;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Danger extends Component
{
    public $resource;
    public array $parameters;
    public ?string $modalId = null;

    public function mount()
    {
        $this->modalId = new Cuid2(7);
        $this->parameters = get_route_parameters();
    }

    public function delete()
    {
        try {
            DeleteResourceJob::dispatchSync($this->resource);
            return $this->redirectRoute('project.resources', [
                'project_uuid' => $this->parameters['project_uuid'],
                'environment_name' => $this->parameters['environment_name']
            ], navigate: true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
