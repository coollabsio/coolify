<?php

namespace App\Http\Livewire\Team;

use App\Models\Team;
use Livewire\Component;

class Form extends Component
{
    public Team $team;
    protected $rules = [
        'team.name' => 'required|min:3|max:255',
        'team.description' => 'nullable|min:3|max:255',
    ];
    protected $validationAttributes = [
        'team.name' => 'name',
        'team.description' => 'description',
    ];

    public function mount()
    {
        $this->team = currentTeam();
    }

    public function submit()
    {
        $this->validate();
        try {
            $this->team->save();
            refreshSession();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
