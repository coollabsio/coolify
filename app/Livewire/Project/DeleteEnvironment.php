<?php

namespace App\Livewire\Project;

use App\Contracts\CustomJobRepositoryInterface;
use App\Jobs\DeleteResourceJob;
use App\Models\Environment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DeleteEnvironment extends Component
{
    use AuthorizesRequests;

    public int $environment_id;

    public bool $disabled = false;

    public string $environmentName = '';

    public array $parameters;

    public bool $queueWorkersAvailable = true;

    public function mount()
    {
        try {
            $this->environmentName = Environment::findOrFail($this->environment_id)->name;
            $this->parameters = get_route_parameters();
            $this->queueWorkersAvailable = $this->hasActiveQueueWorkers();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        if (! $this->queueWorkersAvailable) {
            $this->dispatch('error', 'Queue workers are not running. Start Horizon/queue workers and try again.');

            return;
        }

        $this->validate([
            'environment_id' => 'required|int',
        ]);
        $projectUuid = data_get($this->parameters, 'project_uuid');

        $environment = Environment::query()
            ->where('id', $this->environment_id)
            ->whereHas('project', function ($query) use ($projectUuid) {
                $query->where('uuid', $projectUuid);
            })
            ->firstOrFail();

        $this->authorize('delete', $environment);

        if (! $environment->isEmpty()) {
            $resources = collect()
                ->concat($environment->applications()->get())
                ->concat($environment->databases())
                ->concat($environment->services()->get());

            foreach ($resources as $resource) {
                if (! $resource) {
                    continue;
                }
                $this->authorize('delete', $resource);
                $resource->delete();
                DeleteResourceJob::dispatch($resource, true, true, true, true);
            }
        }

        $environment->delete();

        return redirectRoute($this, 'project.show', ['project_uuid' => $this->parameters['project_uuid']]);
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
