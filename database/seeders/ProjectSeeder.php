<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    private const LIMA_ENVIRONMENTS = [
        ['name' => 'ubuntu24', 'uuid' => 'ubuntu24'],
        ['name' => 'ubuntu26', 'uuid' => 'ubuntu26'],
    ];

    public function run(): void
    {
        $project = Project::create([
            'uuid' => 'project',
            'name' => 'My first project',
            'description' => 'This is a test project in development',
            'team_id' => 0,
        ]);

        foreach (self::LIMA_ENVIRONMENTS as $index => $environment) {
            if ($index === 0) {
                $project->environments()->first()->update($environment);

                continue;
            }

            $project->environments()->create($environment);
        }
    }
}
