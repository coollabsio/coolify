<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * One GitHub App (app_id 1234567890) installed on two accounts, added to
 * Coolify as two sources that differ only by installation id.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();

    $team = Team::factory()->create();

    $createSource = fn (string $name, int $installationId): GithubApp => GithubApp::create([
        'name' => $name,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 1234567890,
        'installation_id' => $installationId,
        'webhook_secret' => 'test-secret',
        'is_public' => false,
        'team_id' => $team->id,
    ]);

    $this->personalSource = $createSource('personal-account', 11111);
    $this->orgSource = $createSource('organization', 22222);

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $this->createApplicationForSource = fn (GithubApp $source, int $repositoryId): Application => Application::create([
        'name' => "app-{$source->name}",
        'git_repository' => "{$source->name}/repo",
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'repository_project_id' => $repositoryId,
        'source_id' => $source->id,
        'source_type' => GithubApp::class,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function multiInstallationDeployedApplicationIds(): array
{
    return ApplicationDeploymentQueue::query()->pluck('application_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
}

function sendMultiInstallationGithubPush(TestCase $test, int $repositoryId, ?int $installationId): TestResponse
{
    $payload = [
        'ref' => 'refs/heads/main',
        'before' => '1111111111111111111111111111111111111111',
        'after' => '2222222222222222222222222222222222222222',
        'repository' => ['id' => $repositoryId, 'full_name' => 'owner/repo'],
        'commits' => [['message' => 'Update', 'added' => [], 'removed' => [], 'modified' => ['README.md']]],
    ];
    if ($installationId !== null) {
        $payload['installation'] = ['id' => $installationId];
    }
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => 'push',
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => '1234567890',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('deploys an application of the second installation of a shared GitHub App', function () {
    $application = ($this->createApplicationForSource)($this->orgSource, 222);

    sendMultiInstallationGithubPush($this, 222, 22222)
        ->assertOk()
        ->assertDontSee('No applications found');

    expect(multiInstallationDeployedApplicationIds())->toBe([$application->id]);
});

it('deploys an application of the first installation of a shared GitHub App', function () {
    $application = ($this->createApplicationForSource)($this->personalSource, 111);

    sendMultiInstallationGithubPush($this, 111, 11111)->assertOk();

    expect(multiInstallationDeployedApplicationIds())->toBe([$application->id]);
});

it('falls back to the GitHub App id when no source has the installation id', function () {
    $application = ($this->createApplicationForSource)($this->personalSource, 111);

    sendMultiInstallationGithubPush($this, 111, 99999)->assertOk();
    expect(multiInstallationDeployedApplicationIds())->toBe([$application->id]);

    ApplicationDeploymentQueue::query()->delete();
    sendMultiInstallationGithubPush($this, 111, null)->assertOk();
    expect(multiInstallationDeployedApplicationIds())->toBe([$application->id]);
});

it('does not match a source of another GitHub App with the same installation id', function () {
    GithubApp::query()->whereKey($this->orgSource->id)->update(['app_id' => 999]);
    ($this->createApplicationForSource)($this->orgSource, 222);

    sendMultiInstallationGithubPush($this, 222, 22222)
        ->assertOk()
        ->assertSee("No applications found with branch 'main'", false);

    expect(ApplicationDeploymentQueue::query()->count())->toBe(0);
});
