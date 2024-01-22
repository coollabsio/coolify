<?php

namespace App\Livewire\Project\Shared;

use App\Models\Environment;
use App\Models\Project;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class MoveResource extends Component
{
    public $resource;
    public $projectUuid;
    public $environmentName;
    public $projects;

    public function mount()
    {
        $parameters = get_route_parameters();
        $this->projectUuid = $parameters['project_uuid'];
        $this->environmentName = $parameters['environment_name'];
        $this->projects = Project::ownedByCurrentTeam()->get();
    }
    public function moveTo($environment_id)
    {
        try {
            $new_environment = Environment::findOrFail($environment_id);
            $this->resource->update([
                'environment_id' => $environment_id
            ]);
            if ($this->resource->type() === 'application') {
                return redirect()->route('project.application.configuration', [
                    'project_uuid' => $new_environment->project->uuid,
                    'environment_name' => $new_environment->name,
                    'application_uuid' => $this->resource->uuid,
                ]);
            } else if (str($this->resource->type())->startsWith('standalone-')) {
                return redirect()->route('project.database.configuration', [
                    'project_uuid' => $new_environment->project->uuid,
                    'environment_name' => $new_environment->name,
                    'database_uuid' => $this->resource->uuid,
                ]);
            }  else if ($this->resource->type() === 'service') {
                return redirect()->route('project.service.configuration', [
                    'project_uuid' => $new_environment->project->uuid,
                    'environment_name' => $new_environment->name,
                    'service_uuid' => $this->resource->uuid,
                ]);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function render()
    {
        return view('livewire.project.shared.move-resource');
    }
}
