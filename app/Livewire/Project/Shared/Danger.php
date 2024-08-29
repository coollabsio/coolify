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

        ray('Mount method called');

        if ($this->resource === null) {
            if (isset($parameters['service_uuid'])) {
                $this->resource = Service::where('uuid', $parameters['service_uuid'])->first();
            } elseif (isset($parameters['stack_service_uuid'])) {
                $this->resource = ServiceApplication::where('uuid', $parameters['stack_service_uuid'])->first()
                    ?? ServiceDatabase::where('uuid', $parameters['stack_service_uuid'])->first();
            }
        }

        ray('Resource:', $this->resource);

        if ($this->resource === null) {
            ray('Resource is null');
            $this->resourceName = 'Unknown Resource';
            return;
        }

        if (!method_exists($this->resource, 'type')) {
            ray('Resource does not have type() method');
            $this->resourceName = 'Unknown Resource';
            return;
        }

        ray('Resource type:', $this->resource->type());

        switch ($this->resource->type()) {
            case 'application':
                $this->resourceName = $this->resource->name ?? 'Application';
                break;
            case 'standalone-postgresql':
            case 'standalone-redis':
            case 'standalone-mongodb':
            case 'standalone-mysql':
            case 'standalone-mariadb':
            case 'standalone-keydb':
            case 'standalone-dragonfly':
            case 'standalone-clickhouse':
                $this->resourceName = $this->resource->name ?? 'Database';
                break;
            case 'service':
                $this->resourceName = $this->resource->name ?? 'Service';
                break;
            case 'service-application':
                $this->resourceName = $this->resource->name ?? 'Service Application';
                break;
            case 'service-database':
                $this->resourceName = $this->resource->name ?? 'Service Database';
                break;
            default:
                $this->resourceName = 'Unknown Resource';
        }

        ray('Final resource name:', $this->resourceName);
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
