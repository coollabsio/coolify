<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::create([
            'uuid' => 'project',
            'name' => 'My first project',
            'description' => 'This is a test project in development',
            'team_id' => 0,
        ]);

        // Update the auto-created environment with a deterministic UUID
        $project->environments()->first()->update(['uuid' => 'production']);
    }
}
