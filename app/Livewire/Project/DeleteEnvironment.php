<?php

namespace App\Livewire\Project;

use App\Models\Environment;
use Livewire\Component;

class DeleteEnvironment extends Component
{
    public int $environment_id;

    public bool $disabled = false;

    public string $environmentName = '';

    public array $parameters;

    public function mount()
    {
        try {
            $this->environmentName = Environment::findOrFail($this->environment_id)->name;
            $this->parameters = get_route_parameters();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        $this->validate([
            'environment_id' => 'required|int',
        ]);
        $environment = Environment::findOrFail($this->environment_id);
        if ($environment->isEmpty()) {
            $environment->delete();

            return redirect()->route('project.show', parameters: ['project_uuid' => $this->parameters['project_uuid']]);
        }

        return $this->dispatch('error', "<strong>Environment {$environment->name}</strong> has defined resources, please delete them first.");
    }
}
