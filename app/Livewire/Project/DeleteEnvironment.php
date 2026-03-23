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
