<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Database\Seeder;

class ApplicationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $environment_1 = Environment::find(1);
        $standalone_docker_1 = StandaloneDocker::find(1);
        $swarm_docker_1 = SwarmDocker::find(1);

        $github_public_source = GithubApp::find(1);
        Application::create([
            'id' => 1,
            'name' => 'My first application',
            'environment_id' => $environment_1->id,
            'destination_id' => $standalone_docker_1->id,
            'destination_type' => StandaloneDocker::class,
            'source_id' => $github_public_source->id,
            'source_type' => GithubApp::class,
        ]);
        Application::create([
            'id' => 2,
            'name' => 'My second application (Swarm)',
            'environment_id' => $environment_1->id,
            'destination_id' => $swarm_docker_1->id,
            'destination_type' => SwarmDocker::class,
            'source_id' => $github_public_source->id,
            'source_type' => GithubApp::class,
        ]);
    }
}
