<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\Source;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    // A public GitHub App skips installation token generation, so only the repository lookup is faked.
    $this->githubApp = GithubApp::create([
        'name' => 'own-github-app',
        'team_id' => $this->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
    ]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'git_repository' => 'git@github.com:acme/shop.git',
        'source_id' => null,
        'source_type' => null,
    ]);

    Http::fake([
        'https://api.github.com/repos/acme/shop' => Http::response(['id' => 4242]),
        '*' => Http::response(['message' => 'Not Found'], 404, ['X-RateLimit-Reset' => now()->timestamp]),
    ]);
});

it('looks up the repository id with the owner/repo slug when the deployment uses an ssh url', function (string $gitRepository) {
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $reflection = new ReflectionObject($job);
    $reflection->getProperty('application')->setValue($job, $this->application);
    $reflection->getProperty('source')->setValue($job, $this->githubApp);
    $reflection->getProperty('customRepository')->setValue($job, $gitRepository);

    $reflection->getMethod('update_repository_project_id')->invoke($job);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/acme/shop');
    expect($this->application->refresh()->repository_project_id)->toBe(4242);
})->with([
    'scp-style ssh url' => ['git@github.com:acme/shop.git'],
    'ssh scheme url' => ['ssh://git@github.com:2222/acme/shop.git'],
    'owner/repo slug' => ['acme/shop'],
]);

it('stores the repository id when an ssh url application switches to a github app source', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(Source::class, ['application' => $this->application])
        ->call('changeSource', $this->githubApp->id, GithubApp::class)
        ->assertDispatched('success');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/acme/shop');
    expect($this->application->refresh()->repository_project_id)->toBe(4242);
});
