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

    public ?string $color = null;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
            ]
        );
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
                'color' => $this->color,
            ]);
        } else {
            $this->name = $this->project->name;
            $this->description = $this->project->description;
            $this->color = $this->project->color;
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
