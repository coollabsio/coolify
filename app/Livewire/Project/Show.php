<?php

namespace App\Livewire\Project;

use App\Models\Environment;
use App\Models\Project;
use App\Support\ValidationPatterns;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Show extends Component
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
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->validate();
            $environment = Environment::create([
                'name' => $this->name,
                'project_id' => $this->project->id,
                'uuid' => (string) new Cuid2,
            ]);

            return redirect()->route('project.resource.index', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $environment->uuid,
            ]);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function navigateToEnvironment($projectUuid, $environmentUuid)
    {
        return redirect()->route('project.resource.index', [
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
        ]);
    }

    public function render()
    {
        return view('livewire.project.show');
    }
}
