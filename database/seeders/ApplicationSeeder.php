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
            'ports_mappings' => '3000:3000',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
            'source_id' => 1,
            'source_type' => GithubApp::class
        ]);
    }
}
