<?php

namespace App\Livewire\Project\Shared;

use App\Jobs\DeleteResourceJob;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\ServiceApplication;
use Livewire\Component;
use Visus\Cuid2\Cuid2;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class Danger extends Component
{
    public $resource;
    public $service;
    public $resourceName;
    public $projectUuid;
    public $environmentName;
    public bool $delete_configurations = true;
    public bool $delete_volumes = true;
    public bool $docker_cleanup = true;
    public bool $delete_connected_networks = true;
    public ?string $modalId = null;
    public string $resourceDomain = '';

    public function mount()
    {
        $parameters = get_route_parameters();
        $this->modalId = new Cuid2;
        $this->projectUuid = data_get($parameters, 'project_uuid');
        $this->environmentName = data_get($parameters, 'environment_name');
        
        // Determine the resource name based on the available properties
        if ($this->resource) {
            $this->resourceName = $this->resource->name ?? 'Resource';
        } elseif ($this->service) {
            $this->resourceName = $this->service->name ?? 'Service'; //this does not get the name of the service
        } else {
            $this->resourceName = 'Unknown Resource'; //service is here?
        }

        ray($this->resourceName);
    }

    public function delete($password)
    {
        if (!Hash::check($password, Auth::user()->password)) {
            $this->addError('password', 'The provided password is incorrect.');
            return;
        }

        if (!$this->resource) {
            $this->addError('resource', 'Resource not found.');
            return;
        }

        try {
            // $this->authorize('delete', $this->resource);
            $this->resource->delete();
            DeleteResourceJob::dispatch(
                $this->resource,
                $this->delete_configurations,
                $this->delete_volumes,
                $this->docker_cleanup,
                $this->delete_connected_networks
            );

            return redirect()->route('project.resource.index', [
                'project_uuid' => $this->projectUuid,
                'environment_name' => $this->environmentName,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.danger');
    }
}
