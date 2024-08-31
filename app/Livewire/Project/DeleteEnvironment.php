<?php

namespace App\Livewire\Project;

use App\Models\Environment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class DeleteEnvironment extends Component
{
    public array $parameters;

    public int $environment_id;

    public bool $disabled = false;

    public string $environmentName = '';

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->environmentName = Environment::findOrFail($this->environment_id)->name;
    }

    public function delete($password)
    {
        if (!Hash::check($password, Auth::user()->password)) {
            $this->addError('password', 'The provided password is incorrect.');
            return;
        }
        $this->validate([
            'environment_id' => 'required|int',
        ]);
        $environment = Environment::findOrFail($this->environment_id);
        if ($environment->isEmpty()) {
            $environment->delete();

            return redirect()->route('project.show', ['project_uuid' => $this->parameters['project_uuid']]);
        }

        return $this->dispatch('error', 'Environment has defined resources, please delete them first.');
    }
}
