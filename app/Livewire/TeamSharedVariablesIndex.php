<?php

namespace App\Livewire;

use App\Models\Team;
use Livewire\Component;

class TeamSharedVariablesIndex extends Component
{
    public Team $team;
    protected $listeners = ['refreshEnvs' => '$refresh', 'saveKey' => 'saveKey'];

    public function saveKey($data)
    {
        try {
            $this->team->environment_variables()->create([
                'key' => $data['key'],
                'value' => $data['value'],
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
        return view('livewire.team-shared-variables-index');
    }
}
