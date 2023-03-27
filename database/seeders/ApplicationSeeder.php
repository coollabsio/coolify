<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ApplicationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $environment_1 = Environment::find(1);
        $application_1 = Application::create([
            'id' => 1,
            'name' => 'My first application',
        ]);
        $environment_1->applications()->attach($application_1);
    }
}
