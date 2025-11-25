<?php

use App\Jobs\CleanupGithubRunnerJob;
use App\Jobs\ProvisionGithubRunnerJob;
use App\Models\GitHubRunner;
use App\Models\GitHubRunnerSource;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

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

test('webhook rejects invalid signature', function () {
    $payload = json_encode(['action' => 'queued']);

    $response = $this->postJson('/webhooks/github-runner/'.$this->source->id, json_decode($payload, true), [
        'X-GitHub-Event' => 'workflow_job',
        'X-Hub-Signature-256' => 'sha256=invalid-signature',
        'X-GitHub-Delivery' => 'test-delivery-id',
    ]);

    $response->assertStatus(401);
});

test('webhook responds to ping event', function () {
    $payload = json_encode(['zen' => 'Testing is fun']);
    $signature = 'sha256='.hash_hmac('sha256', $payload, $this->source->webhook_secret);

    $response = $this->postJson('/webhooks/github-runner/'.$this->source->id, json_decode($payload, true), [
        'X-GitHub-Event' => 'ping',
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Delivery' => 'test-delivery-id',
    ]);

    $response->assertOk();
    $response->assertJson(['message' => 'pong']);
});

test('webhook ignores non-workflow_job events', function () {
    $payload = json_encode(['action' => 'created']);
    $signature = 'sha256='.hash_hmac('sha256', $payload, $this->source->webhook_secret);

    $response = $this->postJson('/webhooks/github-runner/'.$this->source->id, json_decode($payload, true), [
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Delivery' => 'test-delivery-id',
    ]);

    $response->assertOk();
    $response->assertJson(['message' => 'Event ignored']);
});

test('webhook queues provisioning job for matching labels', function () {
    Queue::fake();

    $payload = [
        'action' => 'queued',
        'workflow_job' => [
            'id' => 123456,
            'workflow_name' => 'CI',
            'labels' => ['coolify-test', 'ubuntu-latest'],
        ],
        'repository' => [
            'full_name' => 'test-org/test-repo',
        ],
    ];

    $payloadJson = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $payloadJson, $this->source->webhook_secret);

    $response = $this->postJson('/webhooks/github-runner/'.$this->source->id, $payload, [
        'X-GitHub-Event' => 'workflow_job',
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Delivery' => 'test-delivery-id',
    ]);

    $response->assertOk();
    Queue::assertPushed(ProvisionGithubRunnerJob::class);
});

test('webhook ignores jobs without matching labels', function () {
    Queue::fake();

    $payload = [
        'action' => 'queued',
        'workflow_job' => [
            'id' => 123456,
            'workflow_name' => 'CI',
            'labels' => ['ubuntu-latest', 'different-label'],
        ],
        'repository' => [
            'full_name' => 'test-org/test-repo',
        ],
    ];

    $payloadJson = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $payloadJson, $this->source->webhook_secret);

    $response = $this->postJson('/webhooks/github-runner/'.$this->source->id, $payload, [
        'X-GitHub-Event' => 'workflow_job',
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Delivery' => 'test-delivery-id',
    ]);

    $response->assertOk();
    Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
});

test('webhook marks runner as running on in_progress event', function () {
    $runner = GitHubRunner::create([
        'github_runner_source_id' => $this->source->id,
        'server_id' => $this->server->id,
        'runner_id' => '999',
        'runner_name' => 'test-runner',
        'job_id' => '123456',
        'workflow_name' => 'CI',
        'repository_name' => 'test-org/test-repo',
        'status' => 'queued',
    ]);

    $payload = [
        'action' => 'in_progress',
        'workflow_job' => [
            'id' => 123456,
            'workflow_name' => 'CI',
            'labels' => ['coolify-test'],
        ],
    ];

    $payloadJson = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $payloadJson, $this->source->webhook_secret);

    $response = $this->postJson('/webhooks/github-runner/'.$this->source->id, $payload, [
        'X-GitHub-Event' => 'workflow_job',
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Delivery' => 'test-delivery-id',
    ]);

    $response->assertOk();
    expect($runner->fresh()->status)->toBe('running');
});

test('webhook marks runner as completed and queues cleanup on successful completion', function () {
    Queue::fake();

    $runner = GitHubRunner::create([
        'github_runner_source_id' => $this->source->id,
        'server_id' => $this->server->id,
        'runner_id' => '999',
        'runner_name' => 'test-runner',
        'job_id' => '123456',
        'workflow_name' => 'CI',
        'repository_name' => 'test-org/test-repo',
        'status' => 'running',
    ]);

    $payload = [
        'action' => 'completed',
        'workflow_job' => [
            'id' => 123456,
            'conclusion' => 'success',
            'labels' => ['coolify-test'],
        ],
    ];

    $payloadJson = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $payloadJson, $this->source->webhook_secret);

    $response = $this->postJson('/webhooks/github-runner/'.$this->source->id, $payload, [
        'X-GitHub-Event' => 'workflow_job',
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Delivery' => 'test-delivery-id',
    ]);

    $response->assertOk();
    expect($runner->fresh()->status)->toBe('completed');
    Queue::assertPushed(CleanupGithubRunnerJob::class);
});

test('webhook marks runner as failed on failed completion', function () {
    Queue::fake();

    $runner = GitHubRunner::create([
        'github_runner_source_id' => $this->source->id,
        'server_id' => $this->server->id,
        'runner_id' => '999',
        'runner_name' => 'test-runner',
        'job_id' => '123456',
        'workflow_name' => 'CI',
        'repository_name' => 'test-org/test-repo',
        'status' => 'running',
    ]);

    $payload = [
        'action' => 'completed',
        'workflow_job' => [
            'id' => 123456,
            'conclusion' => 'failure',
            'labels' => ['coolify-test'],
        ],
    ];

    $payloadJson = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $payloadJson, $this->source->webhook_secret);

    $response = $this->postJson('/webhooks/github-runner/'.$this->source->id, $payload, [
        'X-GitHub-Event' => 'workflow_job',
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Delivery' => 'test-delivery-id',
    ]);

    $response->assertOk();
    expect($runner->fresh()->status)->toBe('failed');
    Queue::assertPushed(CleanupGithubRunnerJob::class);
});
