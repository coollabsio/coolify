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

    public bool $delete_configurations = true;

    public bool $delete_volumes = true;

    public ?string $modalId = null;

    public function mount()
    {
        $this->modalId = new Cuid2;
        $parameters = get_route_parameters();
        $this->projectUuid = data_get($parameters, 'project_uuid');
        $this->environmentName = data_get($parameters, 'environment_name');
    }

    public function delete()
    {
        try {
            // $this->authorize('delete', $this->resource);
            $this->resource->delete();
            DeleteResourceJob::dispatch($this->resource, $this->delete_configurations, $this->delete_volumes);

            return redirect()->route('project.resource.index', [
                'project_uuid' => $this->projectUuid,
                'environment_name' => $this->environmentName,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
