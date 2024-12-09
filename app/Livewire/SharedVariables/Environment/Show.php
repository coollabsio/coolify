<?php

namespace App\Livewire\SharedVariables\Environment;

use App\Models\Application;
use App\Models\Project;
use Livewire\Component;

class Show extends Component
{
    public Project $project;

    public Application $application;

    public $environment;

    public array $parameters;

    protected $listeners = ['refreshEnvs' => '$refresh', 'saveKey', 'environmentVariableDeleted' => '$refresh'];

    public function saveKey($data)
    {
        try {
            $found = $this->environment->environment_variables()->where('key', $data['key'])->first();
            if ($found) {
                throw new \Exception('Variable already exists.');
            }
            $this->environment->environment_variables()->create([
                'key' => $data['key'],
                'value' => $data['value'],
                'is_multiline' => $data['is_multiline'],
                'is_literal' => $data['is_literal'],
                'type' => 'environment',
                'team_id' => currentTeam()->id,
            ]);
            $this->environment->refresh();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->project = Project::ownedByCurrentTeam()->where('uuid', request()->route('project_uuid'))->first();
        $this->environment = $this->project->environments()->where('name', request()->route('environment_name'))->first();
    }

    public function render()
    {
        return view('livewire.shared-variables.environment.show');
    }
}
