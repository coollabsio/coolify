<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use App\Models\ScheduledTask;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Add extends Component
{
    public $parameters;

    #[Locked]
    public string $id;

    #[Locked]
    public string $type;

    #[Locked]
    public Collection $containerNames;

    public string $name;

    public string $command;

    public string $frequency;

    public ?string $container = '';

    protected $rules = [
        'name' => 'required|string',
        'command' => 'required|string',
        'frequency' => 'required|string',
        'container' => 'nullable|string',
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
        if ($this->containerNames->count() > 0) {
            $this->container = $this->containerNames->first();
        }
    }

    public function submit()
    {
        try {
            $this->validate();
            $isValid = validate_cron_expression($this->frequency);
            if (! $isValid) {
                $this->dispatch('error', 'Invalid Cron / Human expression.');

                return;
            }
            if (empty($this->container) || $this->container === 'null') {
                if ($this->type === 'service') {
                    $this->container = $this->subServiceName;
                }
            }
            $this->saveScheduledTask();
            $this->clear();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function saveScheduledTask()
    {
        try {
            $task = new ScheduledTask();
            $task->name = $this->name;
            $task->command = $this->command;
            $task->frequency = $this->frequency;
            $task->container = $this->container;
            $task->team_id = currentTeam()->id;

            switch ($this->type) {
                case 'application':
                    $task->application_id = $this->id;
                    break;
                case 'standalone-postgresql':
                    $task->standalone_postgresql_id = $this->id;
                    break;
                case 'service':
                    $task->service_id = $this->id;
                    break;
            }
            $task->save();
            $this->dispatch('refreshTasks');
            $this->dispatch('success', 'Scheduled task added.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function clear()
    {
        $this->name = '';
        $this->command = '';
        $this->frequency = '';
        $this->container = '';
    }
}
