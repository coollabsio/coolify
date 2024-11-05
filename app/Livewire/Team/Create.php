<?php

namespace App\Livewire\Team;

use App\Models\Team;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Create extends Component
{
    #[Validate(['required', 'min:3', 'max:255'])]
    public string $name = '';

    #[Validate(['nullable', 'min:3', 'max:255'])]
    public ?string $description = null;

    public function submit()
    {
        try {
            $this->validate();
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
