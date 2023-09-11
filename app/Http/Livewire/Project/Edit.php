<?php

namespace App\Http\Livewire\Project;

use App\Models\Project;
use Livewire\Component;

class Edit extends Component
{
    public Project $project;
    protected $rules = [
        'project.name' => 'required|min:3|max:255',
        'project.description' => 'nullable|string|max:255',
    ];

    public function submit()
    {
        $this->validate();
        try {
            $this->project->save();
            $this->emit('saved');
        } catch (\Throwable $e) {
            return general_error_handler($e, $this);
        }
    }
}
