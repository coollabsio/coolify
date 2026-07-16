<?php

namespace Database\Seeders;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GitlabApp;
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

    public const GIT_REPOSITORY = 'coollabsio/coolify-examples';

    public const GIT_BRANCH = 'next';

    public const REPOSITORY_PROJECT_ID = 603035348;

    public const LIMA_SERVERS = [
        [
            'server_uuid' => 'lima-ubuntu-2404',
            'server_name' => 'lima-ubuntu-2404',
            'port' => 2222,
            'environment_name' => 'ubuntu24',
            'environment_uuid' => 'railpack-examples-ubuntu24',
            'uuid_prefix' => 'ubuntu24-',
        ],
        [
            'server_uuid' => 'lima-ubuntu-2604',
            'server_name' => 'lima-ubuntu-2604',
            'port' => 2223,
            'environment_name' => 'ubuntu26',
            'environment_uuid' => 'railpack-examples-ubuntu26',
            'uuid_prefix' => 'ubuntu26-',
        ],
    ];

    private const LIMA_SENTINEL_URL = 'http://host.lima.internal:8000';

    public function run(): void
    {
        if (! $this->isDevelopmentEnvironment()) {
            $this->command?->warn('Skipping DevelopmentRailpackExamplesSeeder outside development mode.');

            return;
        }

        $this->ensureDevelopmentPrerequisitesExist();

        if (! StandaloneDocker::query()->find(0)) {
            throw new RuntimeException('StandaloneDocker with id=0 is required before running DevelopmentRailpackExamplesSeeder.');
        }

        $this->cleanupLegacyLimaProjects();
        $this->cleanupLegacyProductionExamples();

        foreach (self::LIMA_SERVERS as $limaServer) {
            $this->seedEnvironment(
                environmentUuid: $limaServer['environment_uuid'],
                environmentName: $limaServer['environment_name'],
                destination: $this->limaDestination($limaServer['server_uuid']),
                uuidPrefix: $limaServer['uuid_prefix'],
                nameSuffix: " ({$limaServer['environment_name']})",
            );
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
            [
                'uuid' => 'railpack-github-deploy-key',
                'name' => 'Railpack GitHub Deploy Key Example',
                'git_repository' => 'git@github.com:coollabsio/coolify-examples-deploy-key.git',
                'git_branch' => 'main',
                'ports_exposes' => '80',
                'private_key_id' => 1,
            ],
            [
                'uuid' => 'railpack-gitlab-deploy-key',
                'name' => 'Railpack GitLab Deploy Key Example',
                'git_repository' => 'git@gitlab.com:coollabsio/php-example.git',
                'git_branch' => 'main',
                'ports_exposes' => '80',
                'source_id' => 1,
                'source_type' => GitlabApp::class,
                'private_key_id' => 1,
            ],
            [
                'uuid' => 'railpack-gitlab-public-example',
                'name' => 'Railpack GitLab Public Example',
                'git_repository' => 'https://gitlab.com/andrasbacsai/coolify-examples.git',
                'git_branch' => 'main',
                'base_directory' => '/astro/static',
                'publish_directory' => '/dist',
                'ports_exposes' => '80',
                'source_id' => 1,
                'source_type' => GitlabApp::class,
                'is_static' => true,
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

        foreach (self::LIMA_SERVERS as $limaServer) {
            $server = Server::query()->firstOrCreate(
                ['uuid' => $limaServer['server_uuid']],
                [
                    'name' => $limaServer['server_name'],
                    'description' => 'This is a Lima VM for local development testing',
                    'ip' => 'host.docker.internal',
                    'port' => $limaServer['port'],
                    'team_id' => 0,
                    'private_key_id' => 1,
                    'proxy' => [
                        'type' => ProxyTypes::TRAEFIK->value,
                        'status' => ProxyStatus::EXITED->value,
                    ],
                ],
            );

            $server->settings->forceFill([
                'sentinel_custom_url' => self::LIMA_SENTINEL_URL,
            ])->saveQuietly();

            StandaloneDocker::query()->firstOrCreate(
                ['server_id' => $server->id],
                [
                    'uuid' => "{$limaServer['server_uuid']}-docker",
                    'name' => "{$limaServer['server_name']} Docker",
                    'network' => 'coolify',
                ],
            );
        }

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
        $this->ensurePublicGitlabSourceExists();
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

    private function ensurePublicGitlabSourceExists(): void
    {
        GitlabApp::query()->firstOrCreate(
            ['id' => 1],
            [
                'uuid' => 'gitlab-public',
                'name' => 'Public GitLab',
                'api_url' => 'https://gitlab.com/api/v4',
                'html_url' => 'https://gitlab.com',
                'is_public' => true,
                'team_id' => 0,
            ],
        );
    }

    private function isDevelopmentEnvironment(): bool
    {
        return in_array(config('app.env'), ['local', 'development', 'dev'], true);
    }

    private function limaDestination(string $serverUuid): StandaloneDocker
    {
        $limaDestination = Server::query()
            ->where('uuid', $serverUuid)
            ->first()
            ?->standaloneDockers()
            ->first();

        if (! $limaDestination) {
            throw new RuntimeException("Lima StandaloneDocker destination is required for {$serverUuid} before running DevelopmentRailpackExamplesSeeder.");
        }

        return $limaDestination;
    }

    private function cleanupLegacyLimaProjects(): void
    {
        Project::query()
            ->whereIn('uuid', [
                'railpack-examples-lima-ubuntu-2404',
                'railpack-examples-lima-ubuntu-2604',
            ])
            ->get()
            ->each(function (Project $project): void {
                Application::withTrashed()
                    ->whereIn('environment_id', $project->environments()->pluck('id'))
                    ->get()
                    ->each
                    ->forceDelete();

                $project->delete();
            });
    }

    private function cleanupLegacyProductionExamples(): void
    {
        $project = Project::query()->where('uuid', self::PROJECT_UUID)->first();

        if (! $project) {
            return;
        }

        Application::withTrashed()
            ->whereIn('environment_id', $project->environments()->pluck('id'))
            ->whereIn('uuid', collect(self::examples())->pluck('uuid'))
            ->get()
            ->each
            ->forceDelete();
    }

    private function seedEnvironment(
        string $environmentUuid,
        string $environmentName,
        StandaloneDocker $destination,
        string $uuidPrefix = '',
        string $nameSuffix = '',
    ): void {
        $environment = $this->prepareEnvironment($environmentUuid, $environmentName);

        foreach (self::examples() as $example) {
            $this->upsertApplication($environment, $destination, $example, $uuidPrefix, $nameSuffix);
        }
    }

    private function prepareEnvironment(string $environmentUuid, string $environmentName): Environment
    {
        $project = Project::query()->firstOrNew(['uuid' => self::PROJECT_UUID]);
        $project->fill([
            'name' => 'Railpack Examples',
            'description' => 'Development-only Railpack examples from coollabsio/coolify-examples@next.',
            'team_id' => 0,
        ]);
        $project->save();

        $environment = $project->environments()
            ->where(function ($query) use ($environmentName, $environmentUuid): void {
                $query
                    ->where('name', $environmentName)
                    ->orWhere('uuid', $environmentUuid);
            })
            ->first();

        $existingEnvironment = $project->environments()->first();

        if (! $environment && $project->environments()->count() === 1 && $existingEnvironment?->name === 'production') {
            $environment = $existingEnvironment;
        }

        if (! $environment) {
            $environment = $project->environments()->create([
                'name' => $environmentName,
                'uuid' => $environmentUuid,
            ]);
        } else {
            $environment->update([
                'name' => $environmentName,
                'uuid' => $environmentUuid,
            ]);
        }

        return $environment;
    }

    /**
     * @param  array<string, mixed>  $example
     */
    private function upsertApplication(Environment $environment, StandaloneDocker $destination, array $example, string $uuidPrefix = '', string $nameSuffix = ''): void
    {
        $uuid = $uuidPrefix.$example['uuid'];
        $name = $example['name'].$nameSuffix;
        $application = Application::withTrashed()->firstOrNew(['uuid' => $uuid]);
        $application->fill([
            'name' => $name,
            'description' => $name,
            'fqdn' => "http://{$uuid}.127.0.0.1.sslip.io",
            'repository_project_id' => $example['repository_project_id'] ?? self::REPOSITORY_PROJECT_ID,
            'git_repository' => $example['git_repository'] ?? self::GIT_REPOSITORY,
            'git_branch' => $example['git_branch'] ?? self::GIT_BRANCH,
            'build_pack' => 'railpack',
            'ports_exposes' => $example['ports_exposes'],
            'base_directory' => $example['base_directory'] ?? '/',
            'publish_directory' => $example['publish_directory'] ?? null,
            'static_image' => 'nginx:alpine',
            'install_command' => $example['install_command'] ?? null,
            'build_command' => $example['build_command'] ?? null,
            'start_command' => $example['start_command'] ?? null,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
            'source_id' => $example['source_id'] ?? 0,
            'source_type' => $example['source_type'] ?? GithubApp::class,
            'private_key_id' => $example['private_key_id'] ?? null,
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
