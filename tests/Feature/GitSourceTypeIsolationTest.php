<?php

use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->team = Team::factory()->create();
    $this->githubApp = GithubApp::create([
        'name' => 'github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 1234567890,
        'webhook_secret' => 'test-secret',
        'is_public' => false,
        'team_id' => $this->team->id,
    ]);
    $this->giteaApp = GiteaApp::create([
        'name' => 'gitea-app',
        'api_url' => 'https://gitea.example.com/api/v1',
        'html_url' => 'https://gitea.example.com',
        'team_id' => $this->team->id,
    ]);
    // Both sources share the same id, so only source_type tells them apart.
    expect($this->giteaApp->id)->toBe($this->githubApp->id);
});

function createSourceTypeIsolationApplication(Team $team, GiteaApp $source): Application
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = $server->standaloneDockers()->firstOrFail();

    return Application::create([
        'name' => 'gitea-sourced-app',
        'git_repository' => 'test-org/test-repo',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'repository_project_id' => 987654321,
        'source_id' => $source->id,
        'source_type' => GiteaApp::class,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

it('does not route a GitHub App webhook to an application of another source type with the same id', function () {
    Queue::fake();
    createSourceTypeIsolationApplication($this->team, $this->giteaApp);

    $body = json_encode([
        'action' => 'closed',
        'number' => 42,
        'repository' => ['id' => 987654321, 'full_name' => 'test-org/test-repo'],
        'pull_request' => [
            'html_url' => 'https://github.com/test-org/test-repo/pull/42',
            'title' => 'Change',
            'author_association' => 'OWNER',
            'head' => ['ref' => 'feature', 'sha' => 'e83c5163316f89bfbde7d9ab23ca2e25604af290'],
            'base' => ['ref' => 'main'],
        ],
    ], JSON_THROW_ON_ERROR);

    $response = $this->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => 'pull_request',
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => '1234567890',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertOk();
    expect($response->getContent())->toContain('Nothing to do');
    Queue::assertNotPushed(ProcessGithubPullRequestWebhook::class);
});

it('does not block GitHub App deletion because of an application of another source type with the same id', function () {
    createSourceTypeIsolationApplication($this->team, $this->giteaApp);

    $this->githubApp->delete();

    expect(GithubApp::find($this->githubApp->id))->toBeNull();
});
