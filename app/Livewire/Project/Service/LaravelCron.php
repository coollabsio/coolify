<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class LaravelCron extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public $applications;

    public array $laravelContainers = [];

    public ?int $selectedContainerForCron = null;

    public bool $isLoadingCron = false;

    public bool $isSchedulerEnabled = false;

    public string $schedulerStatus = '';

    public string $schedulerOutput = '';

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        $this->service = Service::whereUuid(request()->route('service_uuid'))->firstOrFail();
        $this->authorize('view', $this->service);
        $this->applications = $this->service->applications->sort();
        $this->detectLaravelContainers();
    }

    public function detectLaravelContainers(): void
    {
        $this->laravelContainers = [];

        foreach ($this->applications as $application) {
            if (! $this->isLaravelContainer($application)) {
                continue;
            }

            $containerName = $application->name.'-'.$this->service->uuid;
            $this->laravelContainers[] = [
                'id' => $application->id,
                'name' => $application->name,
                'container_name' => $containerName,
                'status' => $application->status,
                'application' => $application,
            ];
        }
    }

    public function isLaravelContainer($application): bool
    {
        $image = strtolower($application->image ?? '');
        if (str_contains($image, 'laravel') || str_contains($image, 'php')) {
            return true;
        }

        $envVars = $application->environment_variables()->get();
        foreach ($envVars as $envVar) {
            $key = strtoupper($envVar->key ?? '');
            if (str_contains($key, 'LARAVEL') || str_contains($key, 'APP_KEY') || str_contains($key, 'APP_ENV')) {
                return true;
            }
        }

        return false;
    }

    public function checkSchedulerStatus(): void
    {
        if (! $this->selectedContainerForCron) {
            return;
        }

        $this->isLoadingCron = true;
        $this->schedulerStatus = '';
        $this->schedulerOutput = '';

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForCron);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');

                return;
            }

            $server = $application->service->server;
            $escapedContainer = escapeshellarg($container['container_name']);

            $checkCommand = "docker exec {$escapedContainer} sh -c 'ps aux | grep \"schedule:run\" | grep -v grep || echo notfound'";
            if ($server->isNonRoot()) {
                $checkCommand = "sudo {$checkCommand}";
            }
            $processCheck = trim(instant_remote_process([$checkCommand], $server, false) ?? '');

            $supervisorCommand = "docker exec {$escapedContainer} sh -c 'supervisorctl status scheduler 2>/dev/null || echo notfound'";
            if ($server->isNonRoot()) {
                $supervisorCommand = "sudo {$supervisorCommand}";
            }
            $supervisorStatus = trim(instant_remote_process([$supervisorCommand], $server, false) ?? '');

            if ($processCheck !== 'notfound' || str_contains($supervisorStatus, 'RUNNING')) {
                $this->isSchedulerEnabled = true;
                $this->schedulerStatus = 'Running';
                $this->schedulerOutput = $supervisorStatus ?: 'Scheduler process is running';
            } else {
                $this->isSchedulerEnabled = false;
                $this->schedulerStatus = 'Stopped';
                $this->schedulerOutput = 'Scheduler is not running';
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error checking scheduler status: '.$e->getMessage());
        } finally {
            $this->isLoadingCron = false;
        }
    }

    public function toggleScheduler(): void
    {
        if (! $this->selectedContainerForCron) {
            return;
        }

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForCron);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');

                return;
            }

            $server = $application->service->server;
            $escapedContainer = escapeshellarg($container['container_name']);

            if ($this->isSchedulerEnabled) {
                $command = "docker exec {$escapedContainer} supervisorctl stop scheduler";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                instant_remote_process([$command], $server, false);
                $this->isSchedulerEnabled = false;
                $this->schedulerStatus = 'Stopped';
            } else {
                $command = "docker exec {$escapedContainer} supervisorctl start scheduler";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                instant_remote_process([$command], $server, false);
                $this->isSchedulerEnabled = true;
                $this->schedulerStatus = 'Running';
            }

            $this->checkSchedulerStatus();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error toggling scheduler: '.$e->getMessage());
        }
    }

    public function runScheduler(): void
    {
        if (! $this->selectedContainerForCron) {
            return;
        }

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForCron);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');

                return;
            }

            $server = $application->service->server;
            $escapedContainer = escapeshellarg($container['container_name']);

            $command = "docker exec {$escapedContainer} php /var/www/html/artisan schedule:run --verbose";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            $this->schedulerOutput = (string) (instant_remote_process([$command], $server, false) ?? '');
            $this->dispatch('success', 'Scheduler executed successfully.');
        } catch (\Throwable $e) {
            $this->schedulerOutput = $e->getMessage();
            $this->dispatch('error', 'Error running scheduler: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.project.service.laravel-cron');
    }
}

