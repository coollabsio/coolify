<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Factories\Factory;
use Visus\Cuid2\Cuid2;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Application>
 */
class ApplicationFactory extends Factory
{
    public function definition()
    {
        return [
            'repository_project_id' => null,
            'uuid' => (string) new Cuid2(7),
            'fqdn' => fake()->domainName(),
            'name' => 'application-'.(string) new Cuid2(7),
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'master',
            'git_commit_sha' => 'HEAD',
            'build_pack' => 'dockerfile',
            'static_image' => 'nginx:alpine',
            'ports_exposes' => '80',
            'base_directory' => '/',
            'publish_directory' => '/',
            'health_check_path' => '/',
            'health_check_host' => 'localhost',
            'health_check_method' => 'GET',
            'health_check_return_code' => 200,
            'health_check_scheme' => 'http',
            'health_check_interval' => 5,
            'health_check_timeout' => 5,
            'health_check_retries' => 3,
            'health_check_start_period' => 5,
            'destination_type' => StandaloneDocker::class,
            'source_type' => GithubApp::class,
            'environment_id' => Environment::factory(),

            'dockerfile' => 'FROM nginx
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
',

        ];
    }
}
