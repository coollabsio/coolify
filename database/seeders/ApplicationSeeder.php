<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\StandaloneDocker;
use Illuminate\Database\Seeder;

class ApplicationSeeder extends Seeder
{
    public const EXAMPLES_REPOSITORY = 'coollabsio/coolify-examples';

    public const EXAMPLES_REPOSITORY_PROJECT_ID = 603035348;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sslipHost = ServerSettingSeeder::sslipHost();

        Application::create([
            'uuid' => 'docker-compose',
            'name' => 'Docker Compose Example',
            'repository_project_id' => self::EXAMPLES_REPOSITORY_PROJECT_ID,
            'git_repository' => self::EXAMPLES_REPOSITORY,
            'git_branch' => 'main',
            'base_directory' => '/internal/compose',
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
            'uuid' => 'dockerfile',
            'name' => 'Dockerfile Example',
            'fqdn' => "http://dockerfile.{$sslipHost}",
            'repository_project_id' => self::EXAMPLES_REPOSITORY_PROJECT_ID,
            'git_repository' => self::EXAMPLES_REPOSITORY,
            'git_branch' => 'main',
            'base_directory' => '/dockerfile/single-stage',
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
            'fqdn' => "http://pure-dockerfile.{$sslipHost}",
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'main',
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
            'git_branch' => 'main',
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

        foreach (self::railpackExamples() as $example) {
            $application = Application::create([
                'uuid' => $example['uuid'],
                'name' => $example['name'],
                'fqdn' => "http://{$example['uuid']}.{$sslipHost}",
                'repository_project_id' => isset($example['git_repository']) ? null : self::EXAMPLES_REPOSITORY_PROJECT_ID,
                'git_repository' => $example['git_repository'] ?? self::EXAMPLES_REPOSITORY,
                'git_branch' => 'main',
                'base_directory' => $example['base_directory'] ?? '/',
                'publish_directory' => $example['publish_directory'] ?? null,
                'build_command' => $example['build_command'] ?? null,
                'start_command' => $example['start_command'] ?? null,
                'build_pack' => 'railpack',
                'static_image' => 'nginx:alpine',
                'ports_exposes' => $example['ports_exposes'],
                'environment_id' => 1,
                'destination_id' => 0,
                'destination_type' => StandaloneDocker::class,
                'source_id' => $example['source_id'] ?? 0,
                'source_type' => $example['source_type'] ?? GithubApp::class,
                'private_key_id' => $example['private_key_id'] ?? null,
            ]);

            $application->settings->update([
                'is_static' => $example['is_static'] ?? false,
                'is_spa' => $example['is_spa'] ?? false,
            ]);
        }
    }

    /**
     * Railpack examples from coollabsio/coolify-examples (main branch) and the deploy key / GitLab examples.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function railpackExamples(): array
    {
        return [
            // JavaScript runtimes
            ['uuid' => 'nodejs', 'name' => 'Node.js Fastify Example', 'base_directory' => '/javascript/runtimes/nodejs', 'ports_exposes' => '3000', 'source_id' => 1],
            ['uuid' => 'bun', 'name' => 'Bun Example', 'base_directory' => '/javascript/runtimes/bun', 'ports_exposes' => '3000'],
            // JavaScript frameworks (server)
            ['uuid' => 'nestjs', 'name' => 'NestJS Example', 'base_directory' => '/javascript/frameworks/nestjs', 'ports_exposes' => '3000', 'start_command' => 'npm run start:prod'],
            ['uuid' => 'adonisjs', 'name' => 'AdonisJS Example', 'base_directory' => '/javascript/frameworks/adonisjs/simple', 'ports_exposes' => '3333'],
            ['uuid' => 'remix', 'name' => 'Remix Example', 'base_directory' => '/javascript/frameworks/remix', 'ports_exposes' => '3000'],
            ['uuid' => 'nextjs-ssr', 'name' => 'Next.js SSR Example', 'base_directory' => '/javascript/frameworks/nextjs/ssr', 'ports_exposes' => '3000'],
            ['uuid' => 'nuxt-server', 'name' => 'Nuxt Server Example', 'base_directory' => '/javascript/frameworks/nuxt/server', 'ports_exposes' => '3000'],
            ['uuid' => 'nuxt-nitro', 'name' => 'Nuxt Nitro Example', 'base_directory' => '/javascript/frameworks/nuxt/nitro', 'ports_exposes' => '3000'],
            ['uuid' => 'astro-server', 'name' => 'Astro Server Example', 'base_directory' => '/javascript/frameworks/astro/server', 'ports_exposes' => '4321', 'start_command' => 'HOST=0.0.0.0 node dist/server/entry.mjs'],
            ['uuid' => 'vue-ssr', 'name' => 'Vue SSR Example', 'base_directory' => '/javascript/frameworks/vue/ssr', 'ports_exposes' => '3000'],
            // JavaScript frameworks and build tools (static)
            ['uuid' => 'nextjs-static', 'name' => 'Next.js Static Example', 'base_directory' => '/javascript/frameworks/nextjs/spa', 'ports_exposes' => '80', 'publish_directory' => '/out', 'is_static' => true],
            ['uuid' => 'nuxt-static', 'name' => 'Nuxt Static Example', 'base_directory' => '/javascript/frameworks/nuxt/static', 'ports_exposes' => '80', 'build_command' => 'npm run generate', 'publish_directory' => '/.output/public', 'is_static' => true],
            ['uuid' => 'astro-static', 'name' => 'Astro Static Example', 'base_directory' => '/javascript/frameworks/astro/static', 'ports_exposes' => '80', 'publish_directory' => '/dist', 'is_static' => true],
            ['uuid' => 'vue-spa', 'name' => 'Vue SPA Example', 'base_directory' => '/javascript/frameworks/vue/spa', 'ports_exposes' => '80', 'publish_directory' => '/dist', 'is_static' => true, 'is_spa' => true],
            ['uuid' => 'vue-spa-router', 'name' => 'Vue SPA Router Example', 'base_directory' => '/javascript/frameworks/vue/spa-router', 'ports_exposes' => '80', 'publish_directory' => '/dist', 'is_static' => true, 'is_spa' => true],
            ['uuid' => 'vite-vanilla-js', 'name' => 'Vite Vanilla JS Example', 'base_directory' => '/javascript/build-tools/vite/vanilla-js', 'ports_exposes' => '80', 'publish_directory' => '/dist', 'is_static' => true],
            ['uuid' => 'vite-vanilla-ts', 'name' => 'Vite Vanilla TS Example', 'base_directory' => '/javascript/build-tools/vite/vanilla-ts', 'ports_exposes' => '80', 'publish_directory' => '/dist', 'is_static' => true],
            ['uuid' => 'static', 'name' => 'Static HTML Example', 'base_directory' => '/static', 'ports_exposes' => '80', 'publish_directory' => '/', 'is_static' => true],
            // PHP
            ['uuid' => 'laravel', 'name' => 'Laravel Example', 'base_directory' => '/php/laravel', 'ports_exposes' => '80'],
            ['uuid' => 'laravel-pure', 'name' => 'Laravel Pure Example', 'base_directory' => '/php/laravel-pure', 'ports_exposes' => '80'],
            ['uuid' => 'laravel-inertia', 'name' => 'Laravel Inertia Example', 'base_directory' => '/php/laravel-inertia', 'ports_exposes' => '80'],
            ['uuid' => 'symfony', 'name' => 'Symfony Example', 'base_directory' => '/php/symfony', 'ports_exposes' => '80'],
            // Other languages
            ['uuid' => 'python-flask', 'name' => 'Python Flask Example', 'base_directory' => '/python/flask', 'ports_exposes' => '5000', 'start_command' => 'flask run --host=0.0.0.0 --port=5000'],
            ['uuid' => 'go-gin', 'name' => 'Go Gin Example', 'base_directory' => '/go/gin', 'ports_exposes' => '3000'],
            ['uuid' => 'rust-rocket', 'name' => 'Rust Rocket Example', 'base_directory' => '/rust/rocket', 'ports_exposes' => '8000'],
            ['uuid' => 'ruby-rails', 'name' => 'Ruby on Rails Example', 'base_directory' => '/ruby/rails', 'ports_exposes' => '3000'],
            ['uuid' => 'elixir-phoenix', 'name' => 'Elixir Phoenix Example', 'base_directory' => '/elixir/phoenix', 'ports_exposes' => '4000'],
            // Deploy keys and GitLab
            ['uuid' => 'github-deploy-key', 'name' => 'GitHub Deploy Key Example', 'git_repository' => 'git@github.com:coollabsio/coolify-examples-deploy-key.git', 'ports_exposes' => '80', 'private_key_id' => 1],
            ['uuid' => 'gitlab-deploy-key', 'name' => 'GitLab Deploy Key Example', 'git_repository' => 'git@gitlab.com:coollabsio/php-example.git', 'ports_exposes' => '80', 'source_id' => 1, 'source_type' => GitlabApp::class, 'private_key_id' => 1],
            ['uuid' => 'gitlab-public-example', 'name' => 'GitLab Public Example', 'git_repository' => 'https://gitlab.com/andrasbacsai/coolify-examples.git', 'base_directory' => '/astro/static', 'ports_exposes' => '80', 'publish_directory' => '/dist', 'source_id' => 1, 'source_type' => GitlabApp::class, 'is_static' => true],
        ];
    }
}
