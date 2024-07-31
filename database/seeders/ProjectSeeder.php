<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        Project::create([
            'name' => 'My first project',
            'description' => 'This is a test project in development',
            'team_id' => 0,
        ]);
    }
}
