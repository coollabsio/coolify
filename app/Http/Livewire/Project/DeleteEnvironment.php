<?php

namespace App\Http\Livewire\Project;

use App\Models\Environment;
use Livewire\Component;

class DeleteEnvironment extends Component
{
    public array $parameters;
    public int $environment_id;

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }

    public function delete()
    {
        $this->validate([
            'environment_id' => 'required|int',
        ]);
        $environment = Environment::findOrFail($this->environment_id);
        if ($environment->isEmpty()) {
            $environment->delete();
            return redirect()->route('project.show', ['project_uuid' => $this->parameters['project_uuid']]);
        }
        return $this->emit('error', 'Environment has defined resources, please delete them first.');
    }
}
