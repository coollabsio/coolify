<?php

namespace Database\Seeders;

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EnvironmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $project_1 = Project::find(1);
        Environment::create([
            'id' => 1,
            'name' => 'production',
            'project_id' => $project_1->id,
        ]);
        Environment::create([
            'id' => 2,
            'name' => 'staging',
            'project_id' => $project_1->id,
        ]);
    }
}
