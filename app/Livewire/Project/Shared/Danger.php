<?php

namespace App\Livewire\Project\Shared;

use App\Contracts\CustomJobRepositoryInterface;
use App\Jobs\DeleteResourceJob;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Danger extends Component
{
    use AuthorizesRequests;

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

    public bool $canDelete = false;

    public bool $queueWorkersAvailable = true;

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

        // Check if user can delete this resource
        try {
            $this->canDelete = auth()->user()->can('delete', $this->resource);
        } catch (\Exception $e) {
            $this->canDelete = false;
        }

        $this->queueWorkersAvailable = $this->hasActiveQueueWorkers();
    }

    public function delete(string $password, array $selectedActions = [])
    {
        if (! $this->queueWorkersAvailable) {
            $this->dispatch('error', 'Queue workers are not running. Start Horizon/queue workers and try again.');

            return;
        }

        if (! verifyPasswordConfirmation($password, $this)) {
            return;
        }

        if (! $this->resource) {
            $this->addError('resource', 'Resource not found.');

            return;
        }

        try {
            if (! empty($selectedActions)) {
                $this->delete_volumes = in_array('delete_volumes', $selectedActions, true);
                $this->delete_connected_networks = in_array('delete_connected_networks', $selectedActions, true);
                $this->delete_configurations = in_array('delete_configurations', $selectedActions, true);
                $this->docker_cleanup = in_array('docker_cleanup', $selectedActions, true);
            }

            $this->authorize('delete', $this->resource);
            $this->resource->delete();
            DeleteResourceJob::dispatch(
                $this->resource,
                $this->delete_volumes,
                $this->delete_connected_networks,
                $this->delete_configurations,
                $this->docker_cleanup
            );

            return redirectRoute($this, 'project.resource.index', [
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

    public function startQueueWorkers(): void
    {
        if (! config('constants.horizon.is_horizon_enabled')) {
            $this->queueWorkersAvailable = true;
            $this->dispatch('success', 'Horizon is disabled in this instance. Queue worker check is not required.');

            return;
        }

        try {
            if (! function_exists('exec') || str_contains((string) ini_get('disable_functions'), 'exec')) {
                $this->dispatch('error', 'PHP exec() is disabled. Please start workers manually from terminal.');

                return;
            }

            $logFile = storage_path('logs/horizon-ui.log');
            $artisanPath = base_path('artisan');
            $phpBinary = PHP_BINARY;
            $command = 'nohup '.escapeshellarg($phpBinary).' '.escapeshellarg($artisanPath).' start:horizon > '.escapeshellarg($logFile).' 2>&1 &';

            $output = [];
            $exitCode = 1;
            exec($command, $output, $exitCode);
            if ($exitCode !== 0) {
                $this->dispatch('error', 'Failed to launch Horizon process. Please start workers manually.');

                return;
            }

            for ($i = 0; $i < 6; $i++) {
                usleep(1000000);
                $this->queueWorkersAvailable = $this->hasActiveQueueWorkers();
                if ($this->queueWorkersAvailable) {
                    $this->dispatch('success', 'Queue workers started successfully.');

                    return;
                }
            }

            $this->dispatch('error', 'Could not confirm workers are running yet. Click Recheck in a few seconds or start them manually.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to start queue workers: '.$e->getMessage());
        }
    }

    public function refreshQueueWorkersStatus(): void
    {
        $this->queueWorkersAvailable = $this->hasActiveQueueWorkers();
        if ($this->queueWorkersAvailable) {
            $this->dispatch('success', 'Queue workers are running.');
        } else {
            $this->dispatch('error', 'Queue workers are still not running.');
        }
    }

    private function hasActiveQueueWorkers(): bool
    {
        if (! config('constants.horizon.is_horizon_enabled')) {
            return true;
        }

        try {
            return app(CustomJobRepositoryInterface::class)->getHorizonWorkers()->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
