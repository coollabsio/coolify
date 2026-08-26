<?php

use App\Jobs\DeleteResourceJob;
use App\Livewire\Project\New\GitlabPrivateRepository;
use App\Livewire\Project\Shared\Webhooks;
use App\Models\Application;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const COOLIFY_GITLAB_EVENTS_URL = 'https://coolify.example.test/webhooks/source/gitlab/events';

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
    ]);

    $this->destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
    ));

    $this->project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments()->first();

    $this->gitlabApp = GitlabApp::create([
        'name' => 'Test GitLab',
        'api_url' => 'https://gitlab.example.test/api/v4',
        'html_url' => 'https://gitlab.example.test',
        'redirect_uri' => 'https://coolify.example.test/webhooks/source/gitlab/redirect',
        'custom_user' => 'git',
        'custom_port' => 22,
        'webhook_token' => 'webhook-token-value', // ggignore
        'access_token' => str_repeat('t', 20), // ggignore
        'refresh_token' => str_repeat('r', 20), // ggignore
        'expires_at' => time() + 3600,
        'team_id' => $this->team->id,
    ]);

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'source_id' => $this->gitlabApp->id,
        'source_type' => $this->gitlabApp->getMorphClass(),
        'repository_project_id' => 42,
        'git_repository' => 'team/app',
        'git_branch' => 'main',
    ]);
});

it('exposes the GitLab events endpoint on the webhooks page', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Webhooks::class, ['resource' => $this->application])
        ->assertSet('gitlabAppWebhookUrl', COOLIFY_GITLAB_EVENTS_URL)
        ->assertSet('gitlabAppWebhookState', 'unknown')
        ->assertSee('Repository webhook');
});

it('reports the webhook as active when GitLab already has it', function () {
    Http::fake(fn (Request $request) => Http::response([
        ['id' => 9, 'url' => COOLIFY_GITLAB_EVENTS_URL],
    ], 200));

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Webhooks::class, ['resource' => $this->application])
        ->call('checkGitlabAppWebhook')
        ->assertSet('gitlabAppWebhookState', 'active');
});

it('reports the webhook as missing when GitLab does not have it', function () {
    Http::fake(fn (Request $request) => Http::response([], 200));

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Webhooks::class, ['resource' => $this->application])
        ->call('checkGitlabAppWebhook')
        ->assertSet('gitlabAppWebhookState', 'missing');
});

it('surfaces the GitLab error instead of failing the page', function () {
    Http::fake(fn (Request $request) => Http::response(['message' => '403 Forbidden'], 403));

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Webhooks::class, ['resource' => $this->application])
        ->call('checkGitlabAppWebhook')
        ->assertSet('gitlabAppWebhookState', 'error')
        ->assertSee('403 Forbidden');
});

it('lets an admin create the webhook from the webhooks page', function () {
    Http::fake(fn (Request $request) => $request->method() === 'POST'
        ? Http::response(['id' => 7], 201)
        : Http::response([], 200));

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Webhooks::class, ['resource' => $this->application])
        ->call('syncGitlabAppWebhook')
        ->assertSet('gitlabAppWebhookState', 'active')
        ->assertDispatched('success');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://gitlab.example.test/api/v4/projects/42/hooks'
        && $request['url'] === COOLIFY_GITLAB_EVENTS_URL);
});

it('does not let a member create the webhook', function () {
    Http::fake();

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Webhooks::class, ['resource' => $this->application])
        ->call('syncGitlabAppWebhook')
        ->assertDispatched('error');

    Http::assertNothingSent();
});

it('keeps the manual webhook form for applications without a Git App', function () {
    $this->application->update(['source_id' => null, 'source_type' => null]);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Webhooks::class, ['resource' => $this->application->fresh()])
        ->assertSet('gitlabAppWebhookUrl', null)
        ->assertSee('Manual Git webhooks')
        ->assertDontSee('Repository webhook');
});

function runGitlabWebhookCleanup(Application $application): void
{
    $job = new DeleteResourceJob($application);

    (new ReflectionMethod($job, 'removeGitlabProjectWebhookIfUnused'))->invoke($job);
}

it('removes the GitLab webhook when the last application using the project is deleted', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response([['id' => 9, 'url' => COOLIFY_GITLAB_EVENTS_URL]], 200)
        : Http::response(null, 204));

    runGitlabWebhookCleanup($this->application);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://gitlab.example.test/api/v4/projects/42/hooks/9');
});

it('keeps the GitLab webhook while another application still deploys the same project', function () {
    Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Second App',
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
        'source_id' => $this->gitlabApp->id,
        'source_type' => $this->gitlabApp->getMorphClass(),
        'repository_project_id' => 42,
        'git_repository' => 'team/app',
        'git_branch' => 'staging',
    ]);

    Http::fake();

    runGitlabWebhookCleanup($this->application);

    Http::assertNothingSent();
});

it('registers the GitLab webhook when an application is created from a GitLab App', function () {
    Http::fake(fn (Request $request) => $request->method() === 'POST'
        ? Http::response(['id' => 7], 201)
        : Http::response([], 200));

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(GitlabPrivateRepository::class, ['type' => 'private-gitlab-app'])
        ->set('gitlab_app_id', $this->gitlabApp->id)
        ->set('selected_gitlab_app_id', $this->gitlabApp->id)
        ->set('selected_project_id', 77)
        ->set('selected_repository_path', 'team/new-app')
        ->set('selected_branch_name', 'main')
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
        ])
        ->set('query', ['destination' => $this->destination->uuid])
        ->call('submit');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://gitlab.example.test/api/v4/projects/77/hooks'
        && $request['url'] === COOLIFY_GITLAB_EVENTS_URL);
});

it('still creates the application when GitLab refuses the webhook', function () {
    Http::fake(fn (Request $request) => Http::response(['message' => '403 Forbidden'], 403));

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(GitlabPrivateRepository::class, ['type' => 'private-gitlab-app'])
        ->set('gitlab_app_id', $this->gitlabApp->id)
        ->set('selected_gitlab_app_id', $this->gitlabApp->id)
        ->set('selected_project_id', 78)
        ->set('selected_repository_path', 'team/other-app')
        ->set('selected_branch_name', 'main')
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
        ])
        ->set('query', ['destination' => $this->destination->uuid])
        ->call('submit');

    expect(Application::where('repository_project_id', 78)->exists())->toBeTrue();
});
