<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use App\Models\ScheduledTask;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

class All extends Component
{
    public $resource;

    public Collection $containerNames;

    public ?string $variables = null;

    public array $parameters;

    protected $listeners = ['refreshTasks', 'saveScheduledTask' => 'submit'];

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
    }

    public function refreshTasks()
    {
        $this->resource->refresh();
    }

    public function submit($data)
    {
        try {
            $scheduledTask = new ScheduledTask;
            $scheduledTask->name = $data['name'];
            $scheduledTask->command = $data['command'];
            $scheduledTask->frequency = $data['frequency'];
            $scheduledTask->container = $data['container'];
            $scheduledTask->team_id = currentTeam()->id;

            switch ($this->resource->type()) {
                case 'application':
                    $scheduledTask->application_id = $this->resource->id;
                    break;
                case 'standalone-postgresql':
                    $scheduledTask->standalone_postgresql_id = $this->resource->id;
                    break;
                case 'service':
                    $scheduledTask->service_id = $this->resource->id;
                    break;
            }
            $scheduledTask->save();
            $this->refreshTasks();
            $this->dispatch('success', 'Scheduled task added.');
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }
}
