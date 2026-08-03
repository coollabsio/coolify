<?php

namespace App\Livewire\Project;

use App\Models\Application;
use App\Models\Project;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EnvironmentEdit extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public Application $application;

    #[Locked]
    public $environment;

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

    public function mount(string $project_uuid, string $environment_uuid)
    {
        try {
            $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
            $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->environment->update([
                'name' => $this->name,
                'description' => $this->description,
                'color' => $this->color,
            ]);
        } else {
            $this->name = $this->environment->name;
            $this->description = $this->environment->description;
            $this->color = $this->environment->color;
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->environment);
            $this->syncData(true);
            redirectRoute($this, 'project.environment.edit', [
                'environment_uuid' => $this->environment->uuid,
                'project_uuid' => $this->project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.environment-edit');
    }
}
