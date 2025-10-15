<?php

namespace App\Livewire\Team;

use App\Models\Team;
use App\Support\ValidationPatterns;
use Livewire\Component;

class Create extends Component
{
    public string $name = '';

    public ?string $description = null;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
        ];
    }

    protected function messages(): array
    {
        return ValidationPatterns::combinedMessages();
    }

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
            refreshSession($team);

            return redirect()->route('team.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
