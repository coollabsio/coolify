<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectSetting;
use Illuminate\Database\Seeder;

class ProjectSettingSeeder extends Seeder
{
    public function run(): void
    {
        $first_project = Project::find(1);
        ProjectSetting::create([
            'id' => 1,
            'wildcard_domain' => 'testing-host.localhost',
            'project_id' => $first_project->id,
        ]);
    }
}
