<?php

namespace Database\Seeders;

use App\Data\ApplicationPreview;
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

        $github_public_source = GithubApp::where('name', 'Public GitHub')->first();

        Application::create([
            'name' => 'coollabsio/coolify-examples:nodejs-fastify',
            'repository_project_id' => 603035348,
            'git_repository' => 'coollabsio/coolify-examples',
            'git_branch' => 'nodejs-fastify',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'ports_mappings' => '3000:3000',
            'environment_id' => $environment_1->id,
            'destination_id' => $standalone_docker_1->id,
            'destination_type' => StandaloneDocker::class,
            'source_id' => $github_public_source->id,
            'source_type' => GithubApp::class,
            'previews' => [
                ApplicationPreview::from([
                    'pullRequestId' => 1,
                    'branch' => 'nodejs-fastify'
                ]),
                ApplicationPreview::from([
                    'pullRequestId' => 2,
                    'branch' => 'nodejs-fastify'
                ])
            ]
        ]);
    }
}
