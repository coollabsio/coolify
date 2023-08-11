<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\StandaloneDocker;
use Illuminate\Database\Seeder;

class ApplicationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Application::create([
            'name' => 'coollabsio/coolify-examples:nodejs-fastify',
            'description' => 'NodeJS Fastify Example',
            'fqdn' => 'http://foo.com',
            'repository_project_id' => 603035348,
            'git_repository' => 'coollabsio/coolify-examples',
            'git_branch' => 'nodejs-fastify',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'ports_mappings' => '3005:3000',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
            'source_id' => 0,
            'source_type' => GithubApp::class
        ]);
        Application::create([
            'name' => 'coollabsio/coolify-examples:dockerfile',
            'description' => 'Dockerfile Example',
            'fqdn' => 'http://foos.com',
            'repository_project_id' => 603035348,
            'git_repository' => 'coollabsio/coolify-examples',
            'git_branch' => 'dockerfile',
            'build_pack' => 'dockerfile',
            'ports_exposes' => '3000',
            'ports_mappings' => '3080:80',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
            'source_id' => 0,
            'source_type' => GithubApp::class
        ]);
    }
}
