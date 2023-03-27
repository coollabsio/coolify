<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Destination;
use App\Models\Environment;
use App\Models\Project;
use App\Models\StandaloneDocker;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ApplicationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $environment_1 = Environment::find(1);
        $standalone_docker_1 = StandaloneDocker::find(1);

        $application_1 = Application::create([
            'id' => 1,
            'name' => 'My first application',
            'destination_id' => $standalone_docker_1->id,
            'destination_type' => StandaloneDocker::class,
        ]);
        $environment_1->applications()->attach($application_1);
    }
}
