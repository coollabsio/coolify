<?php

namespace App\Livewire\Team;

use App\Models\Team;
use Livewire\Component;

class Create extends Component
{
    public string $name = '';

    public ?string $description = null;

    protected $rules = [
        'name' => 'required|min:3|max:255',
        'description' => 'nullable|min:3|max:255',
    ];

    protected $validationAttributes = [
        'name' => 'name',
        'description' => 'description',
    ];

    public function submit()
    {
        $this->validate();
        try {
            $team = Team::create([
                'name' => $this->name,
                'description' => $this->description,
                'personal_team' => false,
            ]);
            auth()->user()->teams()->attach($team, ['role' => 'admin']);
            refreshSession();

            return redirect()->route('team.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
