<?php

namespace App\Livewire\SharedVariables\Project;

use App\Models\Project;
use Livewire\Component;

class Show extends Component
{
    public Project $project;

    protected $listeners = ['refreshEnvs' => '$refresh', 'saveKey' => 'saveKey',  'environmentVariableDeleted' => '$refresh'];

    public function saveKey($data)
    {
        try {
            $found = $this->project->environment_variables()->where('key', $data['key'])->first();
            if ($found) {
                throw new \Exception('Variable already exists.');
            }
            $this->project->environment_variables()->create([
                'key' => $data['key'],
                'value' => $data['value'],
                'is_multiline' => $data['is_multiline'],
                'is_literal' => $data['is_literal'],
                'type' => 'project',
                'team_id' => currentTeam()->id,
            ]);
            $this->project->refresh();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        $projectUuid = request()->route('project_uuid');
        $teamId = currentTeam()->id;
        $project = Project::where('team_id', $teamId)->where('uuid', $projectUuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $this->project = $project;
    }

    public function render()
    {
        return view('livewire.shared-variables.project.show');
    }
}
