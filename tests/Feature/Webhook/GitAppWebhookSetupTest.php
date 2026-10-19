<?php

use App\Livewire\Project\Shared\Webhooks;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'git_repository' => 'test-org/test-repo',
        'git_branch' => 'main',
    ]);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

test('GitLab App sources show the source webhook instead of claiming automatic setup', function () {
    $gitlabApp = GitlabApp::create([
        'name' => 'gitlab-app-webhook-setup-test',
        'api_url' => 'https://gitlab.example.com/api/v4',
        'html_url' => 'https://gitlab.example.com',
        'webhook_token' => 'test-webhook-token',
        'team_id' => $this->team->id,
    ]);
    $this->application->update(['source_id' => $gitlabApp->id, 'source_type' => GitlabApp::class]);

    Livewire::test(Webhooks::class, ['resource' => $this->application->fresh()])
        ->assertSet('gitlabAppWebhook', url('/webhooks/source/gitlab/events'))
        ->assertSee(url('/webhooks/source/gitlab/events'))
        ->assertSee(route('source.gitlab.show', ['gitlab_app_uuid' => $gitlabApp->uuid]))
        ->assertDontSee('test-webhook-token')
        ->assertDontSee('Managed by your Git App');
});

test('GitHub App sources still show that webhooks are managed by the app', function () {
    $githubApp = GithubApp::create([
        'name' => 'github-app-webhook-setup-test',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 1234567890,
        'webhook_secret' => 'test-secret',
        'is_public' => false,
        'team_id' => $this->team->id,
    ]);
    $this->application->update(['source_id' => $githubApp->id, 'source_type' => GithubApp::class]);

    Livewire::test(Webhooks::class, ['resource' => $this->application->fresh()])
        ->assertSet('gitlabAppWebhook', null)
        ->assertSee('Managed by your Git App')
        ->assertDontSee('/webhooks/source/gitlab/events');
});
