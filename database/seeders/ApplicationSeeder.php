<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
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
            'uuid' => 'docker-compose',
            'name' => 'Docker Compose Example',
            'repository_project_id' => 603035348,
            'git_repository' => 'coollabsio/coolify-examples',
            'git_branch' => 'v4.x',
            'base_directory' => '/docker-compose',
            'docker_compose_location' => '/docker-compose-test.yaml',
            'build_pack' => 'dockercompose',
            'ports_exposes' => '80',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
            'source_id' => 1,
            'source_type' => GithubApp::class,
        ]);
        Application::create([
            'uuid' => 'nodejs',
            'name' => 'NodeJS Fastify Example',
            'fqdn' => 'http://nodejs.127.0.0.1.sslip.io',
            'repository_project_id' => 603035348,
            'git_repository' => 'coollabsio/coolify-examples',
            'git_branch' => 'v4.x',
            'base_directory' => '/nodejs',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
            'source_id' => 1,
            'source_type' => GithubApp::class,
        ]);
        Application::create([
            'uuid' => 'dockerfile',
            'name' => 'Dockerfile Example',
            'fqdn' => 'http://dockerfile.127.0.0.1.sslip.io',
            'repository_project_id' => 603035348,
            'git_repository' => 'coollabsio/coolify-examples',
            'git_branch' => 'v4.x',
            'base_directory' => '/dockerfile',
            'build_pack' => 'dockerfile',
            'ports_exposes' => '80',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
            'source_id' => 0,
            'source_type' => GithubApp::class,
        ]);
        Application::create([
            'uuid' => 'dockerfile-pure',
            'name' => 'Pure Dockerfile Example',
            'fqdn' => 'http://pure-dockerfile.127.0.0.1.sslip.io',
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'v4.x',
            'git_commit_sha' => 'HEAD',
            'build_pack' => 'dockerfile',
            'ports_exposes' => '80',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
            'source_id' => 0,
            'source_type' => GithubApp::class,
            'dockerfile' => 'FROM nginx
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
',
        ]);
        Application::create([
            'uuid' => 'crashloop',
            'name' => 'Crash Loop Example',
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'v4.x',
            'git_commit_sha' => 'HEAD',
            'build_pack' => 'dockerfile',
            'ports_exposes' => '80',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
            'source_id' => 0,
            'source_type' => GithubApp::class,
            'dockerfile' => 'FROM alpine
CMD ["sh", "-c", "echo Crashing in 5 seconds... && sleep 5 && exit 1"]
',
        ]);
        Application::create([
            'uuid' => 'github-deploy-key',
            'name' => 'GitHub Deploy Key Example',
            'fqdn' => 'http://github-deploy-key.127.0.0.1.sslip.io',
            'git_repository' => 'git@github.com:coollabsio/coolify-examples-deploy-key.git',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '80',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
            'source_id' => 0,
            'source_type' => GithubApp::class,
            'private_key_id' => 1,
        ]);
        Application::create([
            'uuid' => 'gitlab-deploy-key',
            'name' => 'GitLab Deploy Key Example',
            'fqdn' => 'http://gitlab-deploy-key.127.0.0.1.sslip.io',
            'git_repository' => 'git@gitlab.com:coollabsio/php-example.git',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '80',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
            'source_id' => 1,
            'source_type' => GitlabApp::class,
            'private_key_id' => 1,
        ]);
        Application::create([
            'uuid' => 'gitlab-public-example',
            'name' => 'GitLab Public Example',
            'fqdn' => 'http://gitlab-public.127.0.0.1.sslip.io',
            'git_repository' => 'https://gitlab.com/andrasbacsai/coolify-examples.git',
            'base_directory' => '/astro/static',
            'publish_directory' => '/dist',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '80',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
            'source_id' => 1,
            'source_type' => GitlabApp::class,
        ]);
    }
}
