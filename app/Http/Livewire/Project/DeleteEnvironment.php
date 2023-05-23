<?php

namespace App\Http\Livewire\Project;

use App\Models\Environment;
use App\Models\Project;
use Livewire\Component;

class DeleteEnvironment extends Component
{
    public array $parameters;
    public int $environment_id;
    public int $resource_count = 0;

    public function mount()
    {
        $this->parameters = getParameters();
    }
    public function delete()
    {
        $this->validate([
            'environment_id' => 'required|int',
        ]);
        $environment = Environment::findOrFail($this->environment_id);
        if ($environment->applications->count() > 0) {
            return $this->emit('error', 'Environment has resources defined, please delete them first.');
        }
        $environment->delete();
        return redirect()->route('project.show', ['project_uuid' => $this->parameters['project_uuid']]);
    }
}
