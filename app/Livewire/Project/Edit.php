<?php

namespace App\Livewire\Project;

use App\Models\Project;
use App\Support\ValidationPatterns;
use Livewire\Component;

class Edit extends Component
{
    public Project $project;

    public string $name;

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

    public function mount(string $project_uuid)
    {
        try {
            $this->project = Project::where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->project->update([
                'name' => $this->name,
                'description' => $this->description,
            ]);
        } else {
            $this->name = $this->project->name;
            $this->description = $this->project->description;
        }
    }

    public function submit()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Project updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
