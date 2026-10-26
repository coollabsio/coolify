<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class All extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public $resource;

    #[Locked]
    public array $parameters;

    public Collection $containerNames;

    public ?string $variables = null;

    #[Url(as: 'task_uuid', except: '')]
    public string $taskUuid = '';

    public bool $taskModalOpen = false;

    public function getListeners(): array
    {
        return [
            'modalClosed' => 'closeTask',
        ];
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
        if ($this->resource->type() === 'service') {
            $this->containerNames = $this->resource->applications()->pluck('name');
            $this->containerNames = $this->containerNames->merge($this->resource->databases()->pluck('name'));
        } elseif ($this->resource->type() === 'application') {
            if ($this->resource->build_pack === 'dockercompose') {
                $parsed = $this->resource->parse();
                $containers = collect(data_get($parsed, 'services'))->keys();
                $this->containerNames = $containers;
            } else {
                $this->containerNames = collect([]);
            }
        }

        if ($this->taskUuid !== '') {
            $this->openTask($this->taskUuid);
        }
    }

    public function openTask(string $taskUuid): void
    {
        $this->authorize('view', $this->resource);
        $this->taskUuid = $this->resource->scheduled_tasks()->where('uuid', $taskUuid)->firstOrFail()->uuid;
        $this->taskModalOpen = true;
    }

    public function closeTask(): void
    {
        $this->taskUuid = '';
        $this->taskModalOpen = false;
        $this->resource->refresh();
    }

    #[On('scheduledTaskDeleted')]
    public function taskDeleted(): void
    {
        $this->closeTask();
    }

    #[On('refreshTasks')]
    public function refreshTasks()
    {
        $this->resource->refresh();
    }

    public function render(): View
    {
        return view('livewire.project.shared.scheduled-task.all', [
            'selectedTask' => $this->taskUuid === ''
                ? null
                : $this->resource->scheduled_tasks()->where('uuid', $this->taskUuid)->first(),
        ]);
    }
}
