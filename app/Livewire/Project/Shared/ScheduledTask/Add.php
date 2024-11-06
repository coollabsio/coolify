<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use Illuminate\Support\Collection;
use Livewire\Component;

class Add extends Component
{
    public $parameters;

    public string $type;

    public Collection $containerNames;

    public string $name;

    public string $command;

    public string $frequency;

    public ?string $container = '';

    protected $listeners = ['clearScheduledTask' => 'clear'];

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
            $this->dispatch('saveScheduledTask', [
                'name' => $this->name,
                'command' => $this->command,
                'frequency' => $this->frequency,
                'container' => $this->container,
            ]);
            $this->clear();
        } catch (\Exception $e) {
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
