<?php

namespace App\Http\Livewire\Project;

use App\Models\Project;
use Livewire\Component;

class Edit extends Component
{
    public Project $project;
    public string|null $wildcard_domain = null;
    protected $rules = [
        'project.name' => 'required|min:3|max:255',
        'project.description' => 'nullable|string|max:255',
        'wildcard_domain' => 'nullable|string|max:255',
    ];
    public function mount()
    {
        $this->wildcard_domain = $this->project->settings->wildcard_domain;
    }
    public function submit()
    {
        $this->validate();
        try {
            $this->project->settings->wildcard_domain = $this->wildcard_domain;
            $this->project->settings->save();
            $this->project->save();
            $this->emit('saved');
        } catch (\Exception $e) {
            return general_error_handler($e, $this);
        }
    }
}
