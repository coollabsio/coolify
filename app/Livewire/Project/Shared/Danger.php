<?php

namespace App\Livewire\Project\Shared;

use App\Jobs\DeleteResourceJob;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Danger extends Component
{
    public $resource;
    public $projectUuid;
    public $environmentName;
    public ?string $modalId = null;

    public function mount()
    {
        $this->modalId = new Cuid2(7);
        $parameters = get_route_parameters();
        $this->projectUuid = $parameters['project_uuid'];
        $this->environmentName = $parameters['environment_name'];
    }

    public function delete()
    {
        try {
            DeleteResourceJob::dispatch($this->resource);
            return $this->redirectRoute('project.resources', [
                'project_uuid' => $this->projectUuid,
                'environment_name' => $this->environmentName
            ], navigate: true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
