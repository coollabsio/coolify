<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);

    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->gitlabApp = GitlabApp::create([
        'name' => 'GitLab source',
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'webhook_token' => 'gitlab-webhook-secret',
        'team_id' => $this->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'source_id' => $this->gitlabApp->id,
        'source_type' => GitlabApp::class,
        'repository_project_id' => 1234,
        'git_branch' => 'main',
    ]);
    $this->application->settings->update(['is_auto_deploy_enabled' => true]);

    Bus::fake([ApplicationDeploymentJob::class]);
});

it('queues a deployment for a valid GitLab push webhook', function () {
    $response = $this->withHeader('X-Gitlab-Token', 'gitlab-webhook-secret')
        ->postJson('/webhooks/source/gitlab/events', [
            'object_kind' => 'push',
            'project' => ['id' => 1234],
            'ref' => 'refs/heads/main',
            'after' => '111222333444555666777888999000aaabbbccc1',
            'commits' => [[
                'message' => 'Fix the deployment',
                'added' => ['app/Example.php'],
                'removed' => [],
                'modified' => [],
            ]],
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('0.application', $this->application->name)
        ->assertJsonPath('0.status', 'queued');

    $deployment = ApplicationDeploymentQueue::where('application_id', $this->application->id)->first();

    expect($deployment)->not->toBeNull()
        ->and($deployment->commit)->toBe('111222333444555666777888999000aaabbbccc1')
        ->and((bool) $deployment->is_webhook)->toBeTrue();
});
