<?php

namespace App\Actions\Project;

use App\Models\Environment;
use App\Models\Project;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateEnvironment
{
    use AsAction;

    public function handle(Project $project, string $name): Environment
    {
        if ($project->environments()->where('name', $name)->exists()) {
            throw new \RuntimeException("Environment '{$name}' already exists in this project.");
        }

        return $project->environments()->create(['name' => $name]);
    }
}
