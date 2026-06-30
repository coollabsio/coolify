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

it('can seed the railpack examples directly on a clean development database', function () {
    config()->set('app.env', 'local');

    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    expect(Team::query()->find(0))->not->toBeNull();
    expect(PrivateKey::query()->find(1))->not->toBeNull();
    expect(Server::query()->find(0))->not->toBeNull();
    expect(StandaloneDocker::query()->find(0))->not->toBeNull();
    expect(GithubApp::query()->find(0))->not->toBeNull();
    expect(GitlabApp::query()->find(1))->not->toBeNull();
    expect(Project::query()->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)->exists())->toBeTrue();
    expect(Application::query()->count())->toBe(count(DevelopmentRailpackExamplesSeeder::examples()));
});

it('seeds the railpack examples in development mode', function () {
    config()->set('app.env', 'local');

    seedRailpackExamplePrerequisites();
    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    $project = Project::query()
        ->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)
        ->first();

    expect($project)
        ->not->toBeNull()
        ->and($project->name)->toBe('Railpack Examples')
        ->and($project->environments)->toHaveCount(1)
        ->and($project->environments->first()->uuid)->toBe(DevelopmentRailpackExamplesSeeder::ENVIRONMENT_UUID);

    $applications = $project->applications()->with('settings')->orderBy('uuid')->get();

    expect($applications)->toHaveCount(count(DevelopmentRailpackExamplesSeeder::examples()));
    expect($applications->every(fn (Application $application) => $application->build_pack === 'railpack'))->toBeTrue();
    $examples = collect(DevelopmentRailpackExamplesSeeder::examples())->keyBy('uuid');
    expect($applications->every(
        fn (Application $application) => $application->git_repository === ($examples->get($application->uuid)['git_repository'] ?? DevelopmentRailpackExamplesSeeder::GIT_REPOSITORY)
    ))->toBeTrue();
    expect($applications->every(
        fn (Application $application) => $application->git_branch === ($examples->get($application->uuid)['git_branch'] ?? DevelopmentRailpackExamplesSeeder::GIT_BRANCH)
    ))->toBeTrue();

    $nestjs = $applications->firstWhere('uuid', 'railpack-nestjs');
    $angularStatic = $applications->firstWhere('uuid', 'railpack-angular-static');
    $eleventyStatic = $applications->firstWhere('uuid', 'railpack-eleventy-static');
    $pythonFlask = $applications->firstWhere('uuid', 'railpack-python-flask');
    $goGin = $applications->firstWhere('uuid', 'railpack-go-gin');
    $rust = $applications->firstWhere('uuid', 'railpack-rust');
    $githubDeployKey = $applications->firstWhere('uuid', 'railpack-github-deploy-key');
    $gitlabDeployKey = $applications->firstWhere('uuid', 'railpack-gitlab-deploy-key');
    $gitlabPublic = $applications->firstWhere('uuid', 'railpack-gitlab-public-example');

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
    expect($project->applications()->count())->toBe(count(DevelopmentRailpackExamplesSeeder::examples()));
});
