<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use App\Models\ScheduledTask;
use Livewire\Component;
use Visus\Cuid2\Cuid2;
use Illuminate\Support\Str;

class All extends Component
{
    public $resource;
    public string|null $modalId = null;
    public ?string $variables = null;
    public array $parameters;
    protected $listeners = ['refreshTasks', 'saveScheduledTask' => 'submit'];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->modalId = new Cuid2(7);
    }
    public function refreshTasks()
    {
        $this->resource->refresh();
    }

    public function submit($data)
    {
        try {
            $task = new ScheduledTask();
            $task->name = $data['name'];
            $task->command = $data['command'];
            $task->frequency = $data['frequency'];
            $task->container = $data['container'];
            $task->team_id = currentTeam()->id;

            switch ($this->resource->type()) {
                case 'application':
                    $task->application_id = $this->resource->id;
                    break;
                case 'standalone-postgresql':
                    $task->standalone_postgresql_id = $this->resource->id;
                    break;
                case 'service':
                    $task->service_id = $this->resource->id;
                    break;
            }
            $task->save();
            $this->refreshTasks();
            $this->dispatch('success', 'Scheduled task added.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
