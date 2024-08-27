<?php

namespace App\Livewire\Project\Shared;

use App\Jobs\DeleteResourceJob;
use Livewire\Component;
use Visus\Cuid2\Cuid2;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class Danger extends Component
{
    public $resource;

    public $projectUuid;

    public $environmentName;

    public bool $delete_configurations = true;

    public bool $delete_volumes = true;

    public bool $docker_cleanup = true;

    public bool $delete_connected_networks = true;

    public ?string $modalId = null;

    public function mount()
    {
        $this->modalId = new Cuid2;
        $parameters = get_route_parameters();
        $this->projectUuid = data_get($parameters, 'project_uuid');
        $this->environmentName = data_get($parameters, 'environment_name');
    }

    public function delete($selectedActions, $password)
    {
        if (!Hash::check($password, Auth::user()->password)) {
            $this->addError('password', 'The provided password is incorrect.');
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
}
