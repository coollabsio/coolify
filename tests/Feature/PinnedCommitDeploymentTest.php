<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();

    $this->team = Team::factory()->create();
    $this->project = Project::create([
        'name' => 'Pinned Commit Project',
        'team_id' => $this->team->id,
    ]);
    $this->environment = Environment::create([
        'name' => 'production',
        'project_id' => $this->project->id,
    ]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->first();
});

test('pinned commit overrides queued commit', function () {
    $pinnedCommit = str_repeat('a', 40);
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_type' => StandaloneDocker::class,
        'destination_id' => $this->destination->id,
        'git_commit_sha' => $pinnedCommit,
    ]);

    $deploymentUuid = 'pinned-commit-deployment';
    queue_application_deployment(
        application: $application,
        deployment_uuid: $deploymentUuid,
        commit: str_repeat('b', 40),
        is_webhook: true
    );

    $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $deploymentUuid)->first();
    expect($deployment)->not->toBeNull();
    expect($deployment->commit)->toBe($pinnedCommit);
});

test('un-pinned commit keeps the requested commit', function () {
    $requestedCommit = str_repeat('c', 40);
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_type' => StandaloneDocker::class,
        'destination_id' => $this->destination->id,
        'git_commit_sha' => 'HEAD',
    ]);

    $deploymentUuid = 'head-commit-deployment';
    queue_application_deployment(
        application: $application,
        deployment_uuid: $deploymentUuid,
        commit: $requestedCommit
    );

    $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $deploymentUuid)->first();
    expect($deployment)->not->toBeNull();
    expect($deployment->commit)->toBe($requestedCommit);
});
