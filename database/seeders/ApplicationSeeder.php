<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\LocalPersistentVolume;
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

        $github_public_source = GithubApp::where('name', 'Public GitHub')->first();
        $github_private_source = GithubApp::where('name', 'coolify-laravel-development-private-github')->first();
        $github_private_source_with_deploy_key = GithubApp::where('name', 'Private GitHub (deployment key)')->first();

        $pv_storage = LocalPersistentVolume::find(1);
        Application::create([
            'name' => 'Public application (from GitHub)',
            'git_repository' => 'coollabsio/coolify-examples',
            'git_branch' => 'nodejs-fastify',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'ports_mappings' => '3000:3000,3010:3001',
            'environment_id' => $environment_1->id,
            'destination_id' => $standalone_docker_1->id,
            'destination_type' => StandaloneDocker::class,
            'source_id' => $github_public_source->id,
            'source_type' => GithubApp::class,
        ]);
        // Application::create([
        //     'name' => 'Private application (through GitHub App)',
        //     'git_repository' => 'coollabsio/nodejs-example',
        //     'git_branch' => 'main',
        //     'build_pack' => 'nixpacks',
        //     'ports_exposes' => '3000',
        //     'ports_mappings' => '3001:3000',
        //     'environment_id' => $environment_1->id,
        //     'destination_id' => $standalone_docker_1->id,
        //     'destination_type' => StandaloneDocker::class,
        //     'source_id' => $github_private_source->id,
        //     'source_type' => GithubApp::class,
        // ]);
        // Application::create([
        //     'name' => 'Public application (from GitHub through Deploy Key)',
        //     'git_repository' => 'coollabsio/php',
        //     'git_branch' => 'main',
        //     'build_pack' => 'nixpacks',
        //     'ports_exposes' => '80,3000',
        //     'ports_mappings' => '3002:80',
        //     'environment_id' => $environment_1->id,
        //     'destination_id' => $standalone_docker_1->id,
        //     'destination_type' => StandaloneDocker::class,
        //     'source_id' => $github_private_source_with_deploy_key->id,
        //     'source_type' => GithubApp::class,
        // ]);
    }
}
