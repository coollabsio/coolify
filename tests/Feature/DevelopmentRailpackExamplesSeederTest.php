<?php

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Database\Seeders\DevelopmentRailpackExamplesSeeder;
use Database\Seeders\GithubAppSeeder;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\ServerSeeder;
use Database\Seeders\StandaloneDockerSeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function seedRailpackExamplePrerequisites(): void
{
    test()->seed([
        UserSeeder::class,
        TeamSeeder::class,
        PrivateKeySeeder::class,
        ServerSeeder::class,
        ProjectSeeder::class,
        StandaloneDockerSeeder::class,
        GithubAppSeeder::class,
    ]);
}

function limaServers(): Collection
{
    return collect(DevelopmentRailpackExamplesSeeder::LIMA_SERVERS);
}

it('can seed the railpack examples directly on a clean development database', function () {
    config()->set('app.env', 'local');

    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    expect(Team::query()->find(0))->not->toBeNull();
    expect(PrivateKey::query()->find(1))->not->toBeNull();
    expect(Server::query()->find(0))->not->toBeNull();
    expect(StandaloneDocker::query()->find(0))->not->toBeNull();
    expect(GithubApp::query()->find(0))->not->toBeNull();
    expect(GitlabApp::query()->find(1))->not->toBeNull();
    expect(Application::query()->count())->toBe(count(DevelopmentRailpackExamplesSeeder::examples()) * limaServers()->count());

    $project = Project::query()
        ->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)
        ->first();

    expect($project)
        ->not->toBeNull()
        ->and($project->environments)->toHaveCount(limaServers()->count());

    foreach (limaServers() as $limaServer) {
        $server = Server::query()->where('uuid', $limaServer['server_uuid'])->first();

        expect($server)->not->toBeNull();
        expect($server->settings->sentinel_custom_url)->toBe('http://host.lima.internal:8000');
        expect(StandaloneDocker::query()->whereRelation('server', 'uuid', $limaServer['server_uuid'])->exists())->toBeTrue();
        expect($project->environments()->where('uuid', $limaServer['environment_uuid'])->exists())->toBeTrue();
    }
});

it('seeds the railpack examples in development mode', function () {
    config()->set('app.env', 'local');

    seedRailpackExamplePrerequisites();
    $legacyProject = Project::query()->create([
        'uuid' => 'railpack-examples-lima-ubuntu-2404',
        'name' => 'Railpack Examples - lima-ubuntu-2404',
        'description' => 'Legacy generated Railpack examples project',
        'team_id' => 0,
    ]);
    Application::query()->create([
        'uuid' => 'lima-ubuntu-2404-railpack-nextjs-ssr',
        'name' => 'Legacy Railpack Next.js SSR Example',
        'repository_project_id' => DevelopmentRailpackExamplesSeeder::REPOSITORY_PROJECT_ID,
        'git_repository' => DevelopmentRailpackExamplesSeeder::GIT_REPOSITORY,
        'git_branch' => DevelopmentRailpackExamplesSeeder::GIT_BRANCH,
        'build_pack' => 'railpack',
        'ports_exposes' => '3000',
        'environment_id' => $legacyProject->environments()->first()->id,
        'destination_id' => 0,
        'destination_type' => StandaloneDocker::class,
        'source_id' => 0,
        'source_type' => GithubApp::class,
    ]);

    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    $project = Project::query()
        ->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)
        ->first();

    expect($project)
        ->not->toBeNull()
        ->and($project->name)->toBe('Railpack Examples')
        ->and($project->environments)->toHaveCount(limaServers()->count());
    expect(Project::query()->pluck('uuid')->sort()->values()->all())->toBe([
        'project',
        DevelopmentRailpackExamplesSeeder::PROJECT_UUID,
    ]);
    expect(Application::query()->where('uuid', 'lima-ubuntu-2404-railpack-nextjs-ssr')->exists())->toBeFalse();

    $applications = $project->applications()->with('settings')->orderBy('uuid')->get();

    expect($applications)->toHaveCount(count(DevelopmentRailpackExamplesSeeder::examples()) * limaServers()->count());
    expect($applications->every(fn (Application $application) => $application->build_pack === 'railpack'))->toBeTrue();

    $examples = collect(DevelopmentRailpackExamplesSeeder::examples())->keyBy('uuid');
    expect($applications->every(
        fn (Application $application) => $application->git_repository === ($examples->get(str($application->uuid)->after('-')->value())['git_repository'] ?? DevelopmentRailpackExamplesSeeder::GIT_REPOSITORY)
    ))->toBeTrue();
    expect($applications->every(
        fn (Application $application) => $application->git_branch === ($examples->get(str($application->uuid)->after('-')->value())['git_branch'] ?? DevelopmentRailpackExamplesSeeder::GIT_BRANCH)
    ))->toBeTrue();

    foreach (limaServers() as $limaServer) {
        $limaEnvironment = $project->environments()
            ->where('uuid', $limaServer['environment_uuid'])
            ->first();

        expect($limaEnvironment)
            ->not->toBeNull()
            ->and($limaEnvironment->name)->toBe($limaServer['environment_name']);

        $limaApplications = $limaEnvironment->applications()->with('settings', 'destination.server')->orderBy('uuid')->get();

        expect($limaApplications)->toHaveCount(count(DevelopmentRailpackExamplesSeeder::examples()));
        expect($limaApplications->every(fn (Application $application) => $application->build_pack === 'railpack'))->toBeTrue();
        expect($limaApplications->every(fn (Application $application) => str($application->uuid)->startsWith($limaServer['uuid_prefix'])))->toBeTrue();
        expect($limaApplications->every(fn (Application $application) => $application->destination->server->uuid === $limaServer['server_uuid']))->toBeTrue();
        expect($limaApplications->every(
            fn (Application $application) => $application->git_repository === ($examples->get(str($application->uuid)->after($limaServer['uuid_prefix'])->value())['git_repository'] ?? DevelopmentRailpackExamplesSeeder::GIT_REPOSITORY)
        ))->toBeTrue();
        expect($limaApplications->every(
            fn (Application $application) => $application->git_branch === ($examples->get(str($application->uuid)->after($limaServer['uuid_prefix'])->value())['git_branch'] ?? DevelopmentRailpackExamplesSeeder::GIT_BRANCH)
        ))->toBeTrue();
    }

    $nestjs = $applications->firstWhere('uuid', 'ubuntu24-railpack-nestjs');
    $angularStatic = $applications->firstWhere('uuid', 'ubuntu24-railpack-angular-static');
    $eleventyStatic = $applications->firstWhere('uuid', 'ubuntu24-railpack-eleventy-static');
    $pythonFlask = $applications->firstWhere('uuid', 'ubuntu24-railpack-python-flask');
    $goGin = $applications->firstWhere('uuid', 'ubuntu24-railpack-go-gin');
    $rust = $applications->firstWhere('uuid', 'ubuntu24-railpack-rust');
    $githubDeployKey = $applications->firstWhere('uuid', 'ubuntu24-railpack-github-deploy-key');
    $gitlabDeployKey = $applications->firstWhere('uuid', 'ubuntu24-railpack-gitlab-deploy-key');
    $gitlabPublic = $applications->firstWhere('uuid', 'ubuntu24-railpack-gitlab-public-example');

    expect($nestjs)
        ->not->toBeNull()
        ->and($nestjs->base_directory)->toBe('/node/nestjs')
        ->and($nestjs->ports_exposes)->toBe('3000')
        ->and($nestjs->build_command)->toBe('npm run build')
        ->and($nestjs->start_command)->toBe('npm run start:prod')
        ->and($nestjs->settings->is_static)->toBeFalse();

    expect($angularStatic)
        ->not->toBeNull()
        ->and($angularStatic->publish_directory)->toBe('/dist/static/browser')
        ->and($angularStatic->ports_exposes)->toBe('80')
        ->and($angularStatic->settings->is_static)->toBeTrue()
        ->and($angularStatic->settings->is_spa)->toBeTrue();

    expect($eleventyStatic)
        ->not->toBeNull()
        ->and($eleventyStatic->publish_directory)->toBe('/_site')
        ->and($eleventyStatic->settings->is_static)->toBeTrue()
        ->and($eleventyStatic->settings->is_spa)->toBeFalse();

    expect($pythonFlask)
        ->not->toBeNull()
        ->and($pythonFlask->ports_exposes)->toBe('5000')
        ->and($pythonFlask->start_command)->toBe('flask run --host=0.0.0.0 --port=5000');

    expect($goGin)
        ->not->toBeNull()
        ->and($goGin->ports_exposes)->toBe('3000');

    expect($rust)
        ->not->toBeNull()
        ->and($rust->ports_exposes)->toBe('8000');

    expect($githubDeployKey)
        ->not->toBeNull()
        ->and($githubDeployKey->git_repository)->toBe('git@github.com:coollabsio/coolify-examples-deploy-key.git')
        ->and($githubDeployKey->git_branch)->toBe('main')
        ->and($githubDeployKey->build_pack)->toBe('railpack')
        ->and($githubDeployKey->private_key_id)->toBe(1)
        ->and($githubDeployKey->source_type)->toBe(GithubApp::class)
        ->and($githubDeployKey->source_id)->toBe(0);

    expect($gitlabDeployKey)
        ->not->toBeNull()
        ->and($gitlabDeployKey->git_repository)->toBe('git@gitlab.com:coollabsio/php-example.git')
        ->and($gitlabDeployKey->git_branch)->toBe('main')
        ->and($gitlabDeployKey->build_pack)->toBe('railpack')
        ->and($gitlabDeployKey->private_key_id)->toBe(1)
        ->and($gitlabDeployKey->source_type)->toBe(GitlabApp::class)
        ->and($gitlabDeployKey->source_id)->toBe(1);

    expect($gitlabPublic)
        ->not->toBeNull()
        ->and($gitlabPublic->git_repository)->toBe('https://gitlab.com/andrasbacsai/coolify-examples.git')
        ->and($gitlabPublic->base_directory)->toBe('/astro/static')
        ->and($gitlabPublic->publish_directory)->toBe('/dist')
        ->and($gitlabPublic->build_pack)->toBe('railpack')
        ->and($gitlabPublic->source_type)->toBe(GitlabApp::class)
        ->and($gitlabPublic->settings->is_static)->toBeTrue();
});

it('skips the railpack examples outside development mode', function () {
    config()->set('app.env', 'testing');

    seedRailpackExamplePrerequisites();
    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    expect(Project::query()->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)->exists())->toBeFalse();
    expect(Application::query()->where('uuid', 'railpack-nextjs-ssr')->exists())->toBeFalse();

    foreach (limaServers() as $limaServer) {
        expect(Project::query()->where('uuid', 'railpack-examples-lima-ubuntu-2404')->exists())->toBeFalse();
        expect(Project::query()->where('uuid', 'railpack-examples-lima-ubuntu-2604')->exists())->toBeFalse();
        expect(Application::query()->where('uuid', $limaServer['uuid_prefix'].'railpack-nextjs-ssr')->exists())->toBeFalse();
    }
});

it('is idempotent when run multiple times', function () {
    config()->set('app.env', 'local');

    seedRailpackExamplePrerequisites();
    $this->seed(DevelopmentRailpackExamplesSeeder::class);
    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    $project = Project::query()
        ->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)
        ->first();

    expect($project)->not->toBeNull();
    expect($project->applications()->count())->toBe(count(DevelopmentRailpackExamplesSeeder::examples()) * limaServers()->count());

    foreach (limaServers() as $limaServer) {
        $limaEnvironment = $project->environments()
            ->where('uuid', $limaServer['environment_uuid'])
            ->first();

        expect($limaEnvironment)->not->toBeNull();
        expect($limaEnvironment->applications()->count())->toBe(count(DevelopmentRailpackExamplesSeeder::examples()));
    }
});
