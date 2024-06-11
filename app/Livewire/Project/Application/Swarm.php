<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Swarm extends Component
{
    public Application $application;

    public string $swarm_placement_constraints = '';

    protected $rules = [
        'application.swarm_replicas' => 'required',
        'application.swarm_placement_constraints' => 'nullable',
        'application.settings.is_swarm_only_worker_nodes' => 'required',
    ];

    public function mount()
    {
        if ($this->application->swarm_placement_constraints) {
            $this->swarm_placement_constraints = base64_decode($this->application->swarm_placement_constraints);
        }
    }

    public function instantSave()
    {
        try {
            $this->validate();
            $this->application->settings->save();
            $this->dispatch('success', 'Swarm settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->validate();
            if ($this->swarm_placement_constraints) {
                $this->application->swarm_placement_constraints = base64_encode($this->swarm_placement_constraints);
            } else {
                $this->application->swarm_placement_constraints = null;
            }
            $this->application->save();

            $this->dispatch('success', 'Swarm settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.swarm');
    }
}
