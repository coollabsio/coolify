<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use App\Models\ScheduledTask as ModelsScheduledTask;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Show extends Component
{
    public $parameters;
    public ModelsScheduledTask $task;
    public ?string $modalId = null;
    public bool $isDisabled = false;
    public bool $isLocked = false;
    public string $type;

    protected $rules = [
        'task.name' => 'required|string',
        'task.command' => 'required|string',
    ];
    protected $validationAttributes = [
        'name' => 'Name',
        'command' => 'Command',
    ];

    public function mount()
    {
        $this->modalId = new Cuid2(7);
        $this->parameters = get_route_parameters();
    }

    public function lock()
    {
        $this->task->is_shown_once = true;
        $this->task->save();
        $this->dispatch('refreshTasks');
    }
    public function instantSave()
    {
        $this->submit();
    }
    public function submit()
    {
        $this->validate();
        $this->task->save();
        $this->dispatch('success', 'Environment variable updated successfully.');
        $this->dispatch('refreshTasks');
    }

    public function delete()
    {
        try {
            $this->task->delete();
            $this->dispatch('refreshTasks');
        } catch (\Exception $e) {
            return handleError($e);
        }
    }
}
