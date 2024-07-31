<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use App\Models\Application;
use App\Models\ScheduledTask as ModelsScheduledTask;
use App\Models\Service;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Show extends Component
{
    public $parameters;

    public Application|Service $resource;

    public ModelsScheduledTask $task;

    public ?string $modalId = null;

    public string $type;

    protected $rules = [
        'task.enabled' => 'required|boolean',
        'task.name' => 'required|string',
        'task.command' => 'required|string',
        'task.frequency' => 'required|string',
        'task.container' => 'nullable|string',
    ];

    protected $validationAttributes = [
        'name' => 'name',
        'command' => 'command',
        'frequency' => 'frequency',
        'container' => 'container',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();

        if (data_get($this->parameters, 'application_uuid')) {
            $this->type = 'application';
            $this->resource = Application::where('uuid', $this->parameters['application_uuid'])->firstOrFail();
        } elseif (data_get($this->parameters, 'service_uuid')) {
            $this->type = 'service';
            $this->resource = Service::where('uuid', $this->parameters['service_uuid'])->firstOrFail();
        }

        $this->modalId = new Cuid2;
        $this->task = ModelsScheduledTask::where('uuid', request()->route('task_uuid'))->first();
    }

    public function instantSave()
    {
        $this->validateOnly('task.enabled');
        $this->task->save(['enabled' => $this->task->enabled]);
        $this->dispatch('success', 'Scheduled task updated.');
        $this->dispatch('refreshTasks');
    }

    public function submit()
    {
        $this->validate();
        $this->task->name = str($this->task->name)->trim()->value();
        $this->task->container = str($this->task->container)->trim()->value();
        $this->task->save();
        $this->dispatch('success', 'Scheduled task updated.');
        $this->dispatch('refreshTasks');
    }

    public function delete()
    {
        try {
            $this->task->delete();

            if ($this->type == 'application') {
                return redirect()->route('project.application.configuration', $this->parameters);
            } else {
                return redirect()->route('project.service.configuration', $this->parameters);
            }
        } catch (\Exception $e) {
            return handleError($e);
        }
    }
}
