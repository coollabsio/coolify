<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Swarm extends Component
{
    public Application $application;

    #[Validate('required')]
    public int $swarmReplicas;

    #[Validate(['nullable'])]
    public ?string $swarmPlacementConstraints = null;

    #[Validate('required')]
    public bool $isSwarmOnlyWorkerNodes;

    public function mount()
    {
        try {
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->application->swarm_replicas = $this->swarmReplicas;
            $this->application->swarm_placement_constraints = $this->swarmPlacementConstraints ? base64_encode($this->swarmPlacementConstraints) : null;
            $this->application->settings->is_swarm_only_worker_nodes = $this->isSwarmOnlyWorkerNodes;
            $this->application->save();
            $this->application->settings->save();
        } else {
            $this->swarmReplicas = $this->application->swarm_replicas;
            if ($this->application->swarm_placement_constraints) {
                $this->swarmPlacementConstraints = base64_decode($this->application->swarm_placement_constraints);
            } else {
                $this->swarmPlacementConstraints = null;
            }
            $this->isSwarmOnlyWorkerNodes = $this->application->settings->is_swarm_only_worker_nodes;
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Swarm settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->syncData(true);
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
