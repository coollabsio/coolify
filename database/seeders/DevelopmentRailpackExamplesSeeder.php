<?php

namespace Database\Seeders;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Database\Seeder;
use RuntimeException;

class DevelopmentRailpackExamplesSeeder extends Seeder
{
    public const PROJECT_UUID = 'railpack-examples';

    public const ENVIRONMENT_UUID = 'railpack-examples-production';

    public const GIT_REPOSITORY = 'coollabsio/coolify-examples';

    public const GIT_BRANCH = 'next';

    public const REPOSITORY_PROJECT_ID = 603035348;

    public function run(): void
    {
        if (! $this->isDevelopmentEnvironment()) {
            $this->command?->warn('Skipping DevelopmentRailpackExamplesSeeder outside development mode.');

            return;
        }

        $this->ensureDevelopmentPrerequisitesExist();
        $destination = StandaloneDocker::query()->find(0);

        if (! $destination) {
            throw new RuntimeException('StandaloneDocker with id=0 is required before running DevelopmentRailpackExamplesSeeder.');
        }

        $environment = $this->prepareEnvironment();

        foreach (self::examples() as $example) {
            $this->upsertApplication($environment, $destination, $example);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function examples(): array
    {
        return [
            [
                'uuid' => 'railpack-simple-webserver',
                'name' => 'Railpack Simple Webserver Example',
                'base_directory' => '/node/simple-webserver',
                'ports_exposes' => '3000',
                'start_command' => 'npm run start',
            ],
            [
                'uuid' => 'railpack-expressjs',
                'name' => 'Railpack Express.js Example',
                'base_directory' => '/node/expressjs',
                'ports_exposes' => '3000',
                'start_command' => 'npm run start',
            ],
            [
                'uuid' => 'railpack-fastify',
                'name' => 'Railpack Fastify Example',
                'base_directory' => '/node/fastify',
                'ports_exposes' => '3000',
                'start_command' => 'npm run start',
            ],
            [
                'uuid' => 'railpack-nestjs',
                'name' => 'Railpack NestJS Example',
                'base_directory' => '/node/nestjs',
                'ports_exposes' => '3000',
                'build_command' => 'npm run build',
                'start_command' => 'npm run start:prod',
            ],
            [
                'uuid' => 'railpack-adonisjs',
                'name' => 'Railpack AdonisJS Example',
                'base_directory' => '/node/adonisjs',
                'ports_exposes' => '3333',
                'build_command' => 'npm run build',
                'start_command' => 'npm run start',
            ],
            [
                'uuid' => 'railpack-hono',
                'name' => 'Railpack Hono Example',
                'base_directory' => '/node/hono',
                'ports_exposes' => '3000',
                'build_command' => 'npm run build',
                'start_command' => 'npm run start',
            ],
            [
                'uuid' => 'railpack-koa',
                'name' => 'Railpack Koa Example',
                'base_directory' => '/node/koa',
                'ports_exposes' => '3000',
                'start_command' => 'npm run start',
            ],
            [
                'uuid' => 'railpack-nextjs-ssr',
                'name' => 'Railpack Next.js SSR Example',
                'base_directory' => '/node/nextjs/ssr',
                'ports_exposes' => '3000',
                'build_command' => 'npm run build',
                'start_command' => 'npm run start',
            ],
            [
                'uuid' => 'railpack-nuxtjs-ssr',
                'name' => 'Railpack NuxtJS SSR Example',
                'base_directory' => '/node/nuxtjs/ssr',
                'ports_exposes' => '3000',
                'build_command' => 'npm run build',
                'start_command' => 'npm run preview -- --host 0.0.0.0 --port 3000',
            ],
            [
                'uuid' => 'railpack-astro-ssr',
                'name' => 'Railpack Astro SSR Example',
                'base_directory' => '/node/astro/ssr',
                'ports_exposes' => '4321',
                'build_command' => 'npm run build',
                'start_command' => 'npm run start',
            ],
            [
                'uuid' => 'railpack-sveltekit-ssr',
                'name' => 'Railpack SvelteKit SSR Example',
                'base_directory' => '/node/sveltekit/ssr',
                'ports_exposes' => '3000',
                'build_command' => 'npm run build',
                'start_command' => 'npm run start',
            ],
            [
                'uuid' => 'railpack-tanstack-start-ssr',
                'name' => 'Railpack TanStack Start SSR Example',
                'base_directory' => '/node/tanstack-start/ssr',
                'ports_exposes' => '3000',
                'build_command' => 'npm run build',
                'start_command' => 'npm run start',
            ],
            [
                'uuid' => 'railpack-angular-ssr',
                'name' => 'Railpack Angular SSR Example',
                'base_directory' => '/node/angular/ssr',
                'ports_exposes' => '4000',
                'build_command' => 'npm run build',
                'start_command' => 'npm run start',
            ],
            [
                'uuid' => 'railpack-vue-ssr',
                'name' => 'Railpack Vue SSR Example',
                'base_directory' => '/node/vue/ssr',
                'ports_exposes' => '3000',
                'build_command' => 'npm run build',
                'start_command' => 'npm run start',
            ],
            [
                'uuid' => 'railpack-qwik-ssr',
                'name' => 'Railpack Qwik SSR Example',
                'base_directory' => '/node/qwik/ssr',
                'ports_exposes' => '3000',
                'build_command' => 'npm run build',
                'start_command' => 'npm run serve',
            ],
            [
                'uuid' => 'railpack-react-static',
                'name' => 'Railpack React Static Example',
                'base_directory' => '/node/react',
                'ports_exposes' => '80',
                'build_command' => 'npm run build',
                'publish_directory' => '/dist',
                'is_static' => true,
                'is_spa' => true,
            ],
            [
                'uuid' => 'railpack-vite-static',
                'name' => 'Railpack Vite Static Example',
                'base_directory' => '/node/vite',
                'ports_exposes' => '80',
                'build_command' => 'npm run build',
                'publish_directory' => '/dist',
                'is_static' => true,
                'is_spa' => true,
            ],
            [
                'uuid' => 'railpack-eleventy-static',
                'name' => 'Railpack Eleventy Static Example',
                'base_directory' => '/node/eleventy',
                'ports_exposes' => '80',
                'build_command' => 'npm run build',
                'publish_directory' => '/_site',
                'is_static' => true,
            ],
            [
                'uuid' => 'railpack-gatsby-static',
                'name' => 'Railpack Gatsby Static Example',
                'base_directory' => '/node/gatsby',
                'ports_exposes' => '80',
                'build_command' => 'npm run build',
                'publish_directory' => '/public',
                'is_static' => true,
            ],
            [
                'uuid' => 'railpack-nextjs-static',
                'name' => 'Railpack Next.js Static Example',
                'base_directory' => '/node/nextjs/static',
                'ports_exposes' => '80',
                'build_command' => 'npm run build',
                'publish_directory' => '/out',
                'is_static' => true,
                'is_spa' => true,
            ],
            [
                'uuid' => 'railpack-nuxtjs-static',
                'name' => 'Railpack NuxtJS Static Example',
                'base_directory' => '/node/nuxtjs/static',
                'ports_exposes' => '80',
                'build_command' => 'npm run build',
                'publish_directory' => '/.output/public',
                'is_static' => true,
                'is_spa' => true,
            ],
            [
                'uuid' => 'railpack-astro-static',
                'name' => 'Railpack Astro Static Example',
                'base_directory' => '/node/astro/static',
                'ports_exposes' => '80',
                'build_command' => 'npm run build',
                'publish_directory' => '/dist',
                'is_static' => true,
            ],
            [
                'uuid' => 'railpack-sveltekit-static',
                'name' => 'Railpack SvelteKit Static Example',
                'base_directory' => '/node/sveltekit/static',
                'ports_exposes' => '80',
                'build_command' => 'npm run build',
                'publish_directory' => '/build',
                'is_static' => true,
                'is_spa' => true,
            ],
            [
                'uuid' => 'railpack-tanstack-start-static',
                'name' => 'Railpack TanStack Start Static Example',
                'base_directory' => '/node/tanstack-start/static',
                'ports_exposes' => '80',
                'build_command' => 'npm run build',
                'publish_directory' => '/.output/public',
                'is_static' => true,
                'is_spa' => true,
            ],
            [
                'uuid' => 'railpack-angular-static',
                'name' => 'Railpack Angular Static Example',
                'base_directory' => '/node/angular/static',
                'ports_exposes' => '80',
                'build_command' => 'npm run build',
                'publish_directory' => '/dist/static/browser',
                'is_static' => true,
                'is_spa' => true,
            ],
            [
                'uuid' => 'railpack-vue-static',
                'name' => 'Railpack Vue Static Example',
                'base_directory' => '/node/vue/static',
                'ports_exposes' => '80',
                'build_command' => 'npm run build',
                'publish_directory' => '/dist',
                'is_static' => true,
                'is_spa' => true,
            ],
            [
                'uuid' => 'railpack-qwik-static',
                'name' => 'Railpack Qwik Static Example',
                'base_directory' => '/node/qwik/static',
                'ports_exposes' => '80',
                'build_command' => 'npm run build',
                'publish_directory' => '/dist',
                'is_static' => true,
                'is_spa' => true,
            ],
            // Multi-language examples (only available on v4.x branch).
            [
                'uuid' => 'railpack-python-flask',
                'name' => 'Railpack Python Flask Example',
                'base_directory' => '/flask',
                'ports_exposes' => '5000',
                'git_branch' => 'v4.x',
                'start_command' => 'flask run --host=0.0.0.0 --port=5000',
            ],
            [
                'uuid' => 'railpack-go-gin',
                'name' => 'Railpack Go Gin Example',
                'base_directory' => '/go/gin',
                'ports_exposes' => '3000',
                'git_branch' => 'v4.x',
            ],
            [
                'uuid' => 'railpack-rust',
                'name' => 'Railpack Rust Example',
                'base_directory' => '/rust',
                'ports_exposes' => '8000',
                'git_branch' => 'v4.x',
            ],
            [
                'uuid' => 'railpack-laravel',
                'name' => 'Railpack Laravel Example',
                'base_directory' => '/laravel',
                'ports_exposes' => '80',
                'git_branch' => 'v4.x',
            ],
            [
                'uuid' => 'railpack-laravel-pure',
                'name' => 'Railpack Laravel Pure Example',
                'base_directory' => '/laravel-pure',
                'ports_exposes' => '80',
                'git_branch' => 'v4.x',
            ],
            [
                'uuid' => 'railpack-laravel-inertia',
                'name' => 'Railpack Laravel Inertia Example',
                'base_directory' => '/laravel-inertia',
                'ports_exposes' => '80',
                'git_branch' => 'v4.x',
            ],
            [
                'uuid' => 'railpack-symfony',
                'name' => 'Railpack Symfony Example',
                'base_directory' => '/symfony',
                'ports_exposes' => '80',
                'git_branch' => 'v4.x',
            ],
            [
                'uuid' => 'railpack-rails',
                'name' => 'Railpack Ruby on Rails Example',
                'base_directory' => '/rails-example',
                'ports_exposes' => '3000',
                'git_branch' => 'v4.x',
            ],
            [
                'uuid' => 'railpack-elixir-phoenix',
                'name' => 'Railpack Elixir Phoenix Example',
                'base_directory' => '/elixir-phoenix',
                'ports_exposes' => '4000',
                'git_branch' => 'v4.x',
            ],
            [
                'uuid' => 'railpack-bun',
                'name' => 'Railpack Bun Example',
                'base_directory' => '/bun',
                'ports_exposes' => '3000',
                'git_branch' => 'v4.x',
            ],
        ];
    }

    private function ensureDevelopmentPrerequisitesExist(): void
    {
        Team::query()->firstOrCreate(
            ['id' => 0],
            [
                'name' => 'Root Team',
                'description' => 'The root team',
                'personal_team' => true,
            ],
        );

        PrivateKey::query()->firstOrCreate(
            ['id' => 1],
            [
                'uuid' => 'ssh',
                'team_id' => 0,
                'name' => 'Testing Host Key',
                'description' => 'This is a test docker container',
                'private_key' => <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY,
            ],
        );

        Server::query()->firstOrCreate(
            ['id' => 0],
            [
                'uuid' => 'localhost',
                'name' => 'localhost',
                'description' => 'This is a test docker container in development mode',
                'ip' => 'coolify-testing-host',
                'team_id' => 0,
                'private_key_id' => 1,
                'proxy' => [
                    'type' => ProxyTypes::TRAEFIK->value,
                    'status' => ProxyStatus::EXITED->value,
                ],
            ],
        );

        StandaloneDocker::query()->firstOrCreate(
            ['id' => 0],
            [
                'uuid' => 'docker',
                'name' => 'Standalone Docker 1',
                'network' => 'coolify',
                'server_id' => 0,
            ],
        );

        $this->ensurePublicGithubSourceExists();
    }

    private function ensurePublicGithubSourceExists(): void
    {
        GithubApp::query()->firstOrCreate(
            ['id' => 0],
            [
                'uuid' => 'github-public',
                'name' => 'Public GitHub',
                'api_url' => 'https://api.github.com',
                'html_url' => 'https://github.com',
                'is_public' => true,
                'team_id' => 0,
            ],
        );
    }

    private function isDevelopmentEnvironment(): bool
    {
        return in_array(config('app.env'), ['local', 'development', 'dev'], true);
    }

    private function prepareEnvironment(): Environment
    {
        $project = Project::query()->firstOrNew(['uuid' => self::PROJECT_UUID]);
        $project->fill([
            'name' => 'Railpack Examples',
            'description' => 'Development-only Railpack examples from coollabsio/coolify-examples@next.',
            'team_id' => 0,
        ]);
        $project->save();

        $environment = $project->environments()->first();

        if (! $environment) {
            $environment = $project->environments()->create([
                'name' => 'production',
                'uuid' => self::ENVIRONMENT_UUID,
            ]);
        } else {
            $environment->update([
                'name' => 'production',
                'uuid' => self::ENVIRONMENT_UUID,
            ]);
        }

        return $environment;
    }

    /**
     * @param  array<string, mixed>  $example
     */
    private function upsertApplication(Environment $environment, StandaloneDocker $destination, array $example): void
    {
        $application = Application::withTrashed()->firstOrNew(['uuid' => $example['uuid']]);
        $application->fill([
            'name' => $example['name'],
            'description' => $example['name'],
            'fqdn' => "http://{$example['uuid']}.127.0.0.1.sslip.io",
            'repository_project_id' => self::REPOSITORY_PROJECT_ID,
            'git_repository' => self::GIT_REPOSITORY,
            'git_branch' => $example['git_branch'] ?? self::GIT_BRANCH,
            'build_pack' => 'railpack',
            'ports_exposes' => $example['ports_exposes'],
            'base_directory' => $example['base_directory'],
            'publish_directory' => $example['publish_directory'] ?? null,
            'static_image' => 'nginx:alpine',
            'install_command' => $example['install_command'] ?? null,
            'build_command' => $example['build_command'] ?? null,
            'start_command' => $example['start_command'] ?? null,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
            'source_id' => 0,
            'source_type' => GithubApp::class,
        ]);
        $application->save();

        if ($application->trashed()) {
            $application->restore();
        }

        $application->settings()->updateOrCreate(
            ['application_id' => $application->id],
            [
                'is_static' => $example['is_static'] ?? false,
                'is_spa' => $example['is_spa'] ?? false,
            ],
        );
    }
}
