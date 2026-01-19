<?php

namespace App\Actions\Project;

use App\Models\Environment;
use App\Models\Project;
use App\Models\ProjectSetting;
use Visus\Cuid2\Cuid2;

class CreateProject
{
    public function handle(array $data, bool $createDefaultEnvironment = true): Project
    {
        $project = Project::withoutEvents(function () use ($data) {
            return Project::create($data);
        });

        ProjectSetting::create([
            'project_id' => $project->id,
        ]);

        if ($createDefaultEnvironment) {
            Environment::create([
                'name' => 'production',
                'project_id' => $project->id,
                'uuid' => (string) new Cuid2,
            ]);
        }

        return $project;
    }
}
