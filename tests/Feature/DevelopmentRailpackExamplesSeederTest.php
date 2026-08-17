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

    expect(Team::query()->find(0))->not->toBeNull()
        ->and(PrivateKey::query()->find(1))->not->toBeNull()
        ->and(Server::query()->count())->toBe(1)
        ->and(Server::query()->find(0)?->uuid)->toBe('localhost')
        ->and(StandaloneDocker::query()->find(0))->not->toBeNull()
        ->and(GithubApp::query()->find(0))->not->toBeNull()
        ->and(GitlabApp::query()->find(1))->not->toBeNull()
        ->and(Application::query()->count())->toBe(count(DevelopmentRailpackExamplesSeeder::examples()));

    $project = Project::query()->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)->firstOrFail();

    expect($project->environments)->toHaveCount(1)
        ->and($project->environments->first()->name)->toBe('production')
        ->and($project->applications()->whereRelation('destination.server', 'uuid', 'localhost')->count())
        ->toBe(count(DevelopmentRailpackExamplesSeeder::examples()));
});

it('seeds every railpack example in the production environment on testing-host', function () {
    config()->set('app.env', 'local');

    seedRailpackExamplePrerequisites();
    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    $project = Project::query()->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)->firstOrFail();
    $environment = $project->environments()->sole();
    $applications = $environment->applications()->with('settings', 'destination.server')->orderBy('uuid')->get();

    expect($environment->name)->toBe('production')
        ->and($applications)->toHaveCount(count(DevelopmentRailpackExamplesSeeder::examples()))
        ->and($applications->every(fn (Application $application) => $application->build_pack === 'railpack'))->toBeTrue()
        ->and($applications->every(fn (Application $application) => $application->destination->server->uuid === 'localhost'))->toBeTrue()
        ->and($applications->pluck('uuid')->sort()->values()->all())
        ->toBe(collect(DevelopmentRailpackExamplesSeeder::examples())->pluck('uuid')->sort()->values()->all());

    $nestjs = $applications->firstWhere('uuid', 'railpack-nestjs');
    $angularStatic = $applications->firstWhere('uuid', 'railpack-angular-static');
    $githubDeployKey = $applications->firstWhere('uuid', 'railpack-github-deploy-key');
    $gitlabDeployKey = $applications->firstWhere('uuid', 'railpack-gitlab-deploy-key');

    expect($nestjs->base_directory)->toBe('/node/nestjs')
        ->and($nestjs->build_command)->toBe('npm run build')
        ->and($nestjs->start_command)->toBe('npm run start:prod')
        ->and($angularStatic->publish_directory)->toBe('/dist/static/browser')
        ->and($angularStatic->settings->is_static)->toBeTrue()
        ->and($githubDeployKey->private_key_id)->toBe(1)
        ->and($githubDeployKey->source_type)->toBe(GithubApp::class)
        ->and($gitlabDeployKey->source_type)->toBe(GitlabApp::class);
});

it('consolidates legacy railpack environments into production', function () {
    config()->set('app.env', 'local');

    seedRailpackExamplePrerequisites();
    $project = Project::query()->create([
        'uuid' => DevelopmentRailpackExamplesSeeder::PROJECT_UUID,
        'name' => 'Railpack Examples',
        'team_id' => 0,
    ]);
    $project->environments()->first()->update(['name' => 'ubuntu24', 'uuid' => 'railpack-examples-ubuntu24']);
    $project->environments()->create(['name' => 'ubuntu26', 'uuid' => 'railpack-examples-ubuntu26']);

    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    $project->refresh();

    expect($project->environments)->toHaveCount(1)
        ->and($project->environments->first()->name)->toBe('production')
        ->and($project->applications)->toHaveCount(count(DevelopmentRailpackExamplesSeeder::examples()));
});

it('skips the railpack examples outside development mode', function () {
    config()->set('app.env', 'testing');

    seedRailpackExamplePrerequisites();
    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    expect(Project::query()->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)->exists())->toBeFalse();
});

it('is idempotent when run multiple times', function () {
    config()->set('app.env', 'local');

    seedRailpackExamplePrerequisites();
    $this->seed(DevelopmentRailpackExamplesSeeder::class);
    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    $project = Project::query()->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)->firstOrFail();

    expect($project->environments)->toHaveCount(1)
        ->and($project->applications)->toHaveCount(count(DevelopmentRailpackExamplesSeeder::examples()));
});
