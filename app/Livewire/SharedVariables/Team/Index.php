<?php

namespace App\Livewire\SharedVariables\Team;

use App\Models\Team;
use Livewire\Component;

class Index extends Component
{
    public Team $team;

    protected $listeners = ['refreshEnvs' => '$refresh', 'saveKey' => 'saveKey',  'environmentVariableDeleted' => '$refresh'];

    public function saveKey($data)
    {
        try {
            $found = $this->team->environment_variables()->where('key', $data['key'])->first();
            if ($found) {
                throw new \Exception('Variable already exists.');
            }
            $this->team->environment_variables()->create([
                'key' => $data['key'],
                'value' => $data['value'],
                'is_multiline' => $data['is_multiline'],
                'is_literal' => $data['is_literal'],
                'type' => 'team',
                'team_id' => currentTeam()->id,
            ]);
            $this->team->refresh();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        $this->team = currentTeam();
    }

    public function render()
    {
        return view('livewire.shared-variables.team.index');
    }
}
