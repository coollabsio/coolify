<?php

namespace App\Livewire\Project;

use App\Models\Project;
use App\Services\ProjectIconStorageService;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithFileUploads;

class Edit extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public Project $project;

    public string $name;

    public ?string $description = null;

    public $icon;

    public function uploadIcon(ProjectIconStorageService $iconStorage): bool
    {
        try {
            $this->authorize('update', $this->project);
            $this->validate([
                'icon' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            ]);
            $iconStorage->storeProject($this->project, $this->icon);
            $this->reset('icon');
            $this->project->refresh();
            $this->dispatch('success', 'Project icon updated.');

            return true;
        } catch (\Throwable $e) {
            handleError($e, $this);

            return false;
        }
    }

    public function removeIcon(ProjectIconStorageService $iconStorage): void
    {
        try {
            $this->authorize('update', $this->project);
            $iconStorage->deleteProject($this->project);
            $this->project->refresh();
            $this->dispatch('success', 'Project icon removed.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

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
            $this->authorize('update', $this->project);
            $this->syncData(true);
            $this->dispatch('success', 'Project updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
