<?php

namespace App\Livewire\Concerns;

use App\Models\Environment;
use App\Support\ValidationPatterns;

/**
 * "New environment" modal of the project page and the project canvas.
 * The component must have a $project property.
 */
trait CreatesEnvironments
{
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

    public function submit()
    {
        try {
            $this->authorize('update', $this->project);
            $this->validate();
            $environment = Environment::create([
                'name' => $this->name,
                'project_id' => $this->project->id,
                'uuid' => new_public_id(),
            ]);

            return redirectRoute($this, 'project.resource.index', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $environment->uuid,
            ]);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }
}
