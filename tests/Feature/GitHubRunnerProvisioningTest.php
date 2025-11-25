<?php

use App\Jobs\ProvisionGithubRunnerJob;
use App\Models\GitHubRunner;
use App\Models\GitHubRunnerSource;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->source = GitHubRunnerSource::create([
        'team_id' => $this->team->id,
        'name' => 'Test Runner Source',
        'runner_label' => 'coolify-test',
        'app_id' => 12345,
        'installation_id' => 67890,
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'webhook_secret' => 'test-webhook-secret',
        'organization' => 'test-org',
        'is_organization_level' => true,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->source->servers()->attach($this->server->id, ['is_active' => true]);
});

test('server selection picks server with least load', function () {
    // Create second server
    $server2 = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
    $this->source->servers()->attach($server2->id, ['is_active' => true]);

    // Add running runner to first server
    GitHubRunner::create([
        'github_runner_source_id' => $this->source->id,
        'server_id' => $this->server->id,
        'runner_id' => '123',
        'runner_name' => 'existing-runner',
        'job_id' => '999',
        'workflow_name' => 'CI',
        'repository_name' => 'test/repo',
        'status' => 'running',
    ]);

    $availableServers = $this->source->getAvailableServers();

    // Server 2 should be first (0 runners vs 1 runner)
    expect($availableServers->first()->id)->toBe($server2->id);
});

test('runner source can have multiple servers in pool', function () {
    $server2 = Server::factory()->create(['team_id' => $this->team->id]);
    $server3 = Server::factory()->create(['team_id' => $this->team->id]);

    $this->source->servers()->attach([$server2->id, $server3->id]);

    expect($this->source->servers)->toHaveCount(3);
});

test('inactive servers are excluded from available servers', function () {
    $server2 = Server::factory()->create(['team_id' => $this->team->id]);
    $this->source->servers()->attach($server2->id, ['is_active' => false]);

    $availableServers = $this->source->getAvailableServers();

    expect($availableServers)->toHaveCount(1)
        ->and($availableServers->first()->id)->toBe($this->server->id);
});

test('runner source prevents deletion with active runners', function () {
    GitHubRunner::create([
        'github_runner_source_id' => $this->source->id,
        'server_id' => $this->server->id,
        'runner_id' => '123',
        'runner_name' => 'active-runner',
        'job_id' => '999',
        'workflow_name' => 'CI',
        'repository_name' => 'test/repo',
        'status' => 'running',
    ]);

    expect(fn () => $this->source->delete())
        ->toThrow(\Exception::class, 'cannot delete');
});

test('runner source allows deletion without active runners', function () {
    GitHubRunner::create([
        'github_runner_source_id' => $this->source->id,
        'server_id' => $this->server->id,
        'runner_id' => '123',
        'runner_name' => 'completed-runner',
        'job_id' => '999',
        'workflow_name' => 'CI',
        'repository_name' => 'test/repo',
        'status' => 'completed',
    ]);

    expect(fn () => $this->source->delete())->not->toThrow(\Exception::class);
});

test('runner record is created with correct attributes', function () {
    $runner = GitHubRunner::create([
        'github_runner_source_id' => $this->source->id,
        'server_id' => $this->server->id,
        'runner_id' => '12345',
        'runner_name' => 'test-runner',
        'job_id' => '67890',
        'workflow_name' => 'Test Workflow',
        'repository_name' => 'org/repo',
        'status' => 'queued',
    ]);

    expect($runner->status)->toBe('queued')
        ->and($runner->runner_name)->toBe('test-runner')
        ->and($runner->source->id)->toBe($this->source->id)
        ->and($runner->server->id)->toBe($this->server->id);
});

test('server knows it is a runner server when attached to source', function () {
    expect($this->server->isRunnerServer())->toBeTrue();
});

test('server knows it is not a runner server when not attached', function () {
    $standaloneServer = Server::factory()->create(['team_id' => $this->team->id]);

    expect($standaloneServer->isRunnerServer())->toBeFalse();
});

test('runner source relationship with servers works bidirectionally', function () {
    expect($this->source->servers)->toHaveCount(1)
        ->and($this->server->runnerSources)->toHaveCount(1)
        ->and($this->server->runnerSources->first()->id)->toBe($this->source->id);
});

test('runner source generates correct webhook URL', function () {
    config(['app.url' => 'https://coolify.test']);

    $webhookUrl = $this->source->getWebhookUrl();

    expect($webhookUrl)->toBe('https://coolify.test/webhooks/github-runner/'.$this->source->id);
});
