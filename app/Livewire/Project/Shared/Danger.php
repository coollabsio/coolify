<?php

namespace App\Livewire\Project\Shared;

use App\Jobs\DeleteResourceJob;
use App\Models\InstanceSettings;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Danger extends Component
{
    public $resource;

    public $resourceName;

    public $projectUuid;

    public $environmentUuid;

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
        $this->environmentUuid = data_get($parameters, 'environment_uuid');

        if ($this->resource === null) {
            if (isset($parameters['service_uuid'])) {
                $this->resource = Service::where('uuid', $parameters['service_uuid'])->first();
            } elseif (isset($parameters['stack_service_uuid'])) {
                $this->resource = ServiceApplication::where('uuid', $parameters['stack_service_uuid'])->first()
                    ?? ServiceDatabase::where('uuid', $parameters['stack_service_uuid'])->first();
            }
        }

        if ($this->resource === null) {
            $this->resourceName = 'Unknown Resource';

            return;
        }

        if (! method_exists($this->resource, 'type')) {
            $this->resourceName = 'Unknown Resource';

            return;
        }

        $this->resourceName = match ($this->resource->type()) {
            'application' => $this->resource->name ?? 'Application',
            'standalone-postgresql',
            'standalone-redis',
            'standalone-mongodb',
            'standalone-mysql',
            'standalone-mariadb',
            'standalone-keydb',
            'standalone-dragonfly',
            'standalone-clickhouse' => $this->resource->name ?? 'Database',
            'service' => $this->resource->name ?? 'Service',
            'service-application' => $this->resource->name ?? 'Service Application',
            'service-database' => $this->resource->name ?? 'Service Database',
            default => 'Unknown Resource',
        };
    }

    public function delete($password)
    {
        if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
            if (! Hash::check($password, Auth::user()->password)) {
                $this->addError('password', 'The provided password is incorrect.');

                return;
            }
        }

        if (! $this->resource) {
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
                'environment_uuid' => $this->environmentUuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.danger', [
            'checkboxes' => [
                ['id' => 'delete_volumes', 'label' => __('resource.delete_volumes')],
                ['id' => 'delete_connected_networks', 'label' => __('resource.delete_connected_networks')],
                ['id' => 'delete_configurations', 'label' => __('resource.delete_configurations')],
                ['id' => 'docker_cleanup', 'label' => __('resource.docker_cleanup')],
                // ['id' => 'delete_associated_backups_locally', 'label' => 'All backups associated with this Ressource will be permanently deleted from local storage.'],
                // ['id' => 'delete_associated_backups_s3', 'label' => 'All backups associated with this Ressource will be permanently deleted from the selected S3 Storage.'],
                // ['id' => 'delete_associated_backups_sftp', 'label' => 'All backups associated with this Ressource will be permanently deleted from the selected SFTP Storage.']
            ],
        ]);
    }
}
