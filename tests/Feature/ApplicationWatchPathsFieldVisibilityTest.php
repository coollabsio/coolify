<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);
});

function watchPathsTestApplication(array $attributes): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => StandaloneDocker::class,
        'git_repository' => 'git@github.com:acme/monorepo.git',
        ...$attributes,
    ]);
}

function watchPathsTestGithubApp(bool $isPublic): GithubApp
{
    return GithubApp::create([
        'name' => $isPublic ? 'public-github' : 'private-github-app',
        'team_id' => test()->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => $isPublic,
    ]);
}

/**
 * Regression for #12373: deploy-key applications have no source, but manual Git
 * webhooks still filter their deployments by watch paths.
 */
it('shows the watch paths field for deploy key applications', function (string $buildPack) {
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $application = watchPathsTestApplication([
        'build_pack' => $buildPack,
        'private_key_id' => $privateKey->id,
    ]);

    Livewire::test(General::class, ['application' => $application->fresh()])
        ->assertSee('Watch paths');
})->with(['dockercompose', 'dockerfile', 'nixpacks']);

it('shows the watch paths field for private GitHub App applications', function () {
    $githubApp = watchPathsTestGithubApp(isPublic: false);
    $application = watchPathsTestApplication([
        'source_id' => $githubApp->id,
        'source_type' => GithubApp::class,
    ]);

    Livewire::test(General::class, ['application' => $application->fresh()])
        ->assertSee('Watch paths');
});

it('hides the watch paths field for public repository applications', function () {
    $githubApp = watchPathsTestGithubApp(isPublic: true);
    $application = watchPathsTestApplication([
        'source_id' => $githubApp->id,
        'source_type' => GithubApp::class,
    ]);

    Livewire::test(General::class, ['application' => $application->fresh()])
        ->assertDontSee('Watch paths');
});

it('hides the watch paths field for applications without a Git repository', function () {
    $application = watchPathsTestApplication([
        'build_pack' => 'dockerfile',
        'dockerfile' => "FROM nginx:alpine\n",
    ]);

    Livewire::test(General::class, ['application' => $application->fresh()])
        ->assertDontSee('Watch paths');
});
