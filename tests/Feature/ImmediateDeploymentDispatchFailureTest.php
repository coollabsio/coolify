<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_registry_image_name' => 'bad name;x',
    ]);

    Queue::fake();
    Notification::fake();
});

test('a deployment whose job cannot be dispatched fails and does not block the next deployment', function () {
    expect(fn () => queue_application_deployment($this->application, 'prr-undispatchable'))
        ->toThrow(DeploymentException::class, 'Docker registry image name contains invalid characters.');

    $failedDeployment = ApplicationDeploymentQueue::where('deployment_uuid', 'prr-undispatchable')->firstOrFail();
    expect($failedDeployment->status)->toBe('failed')
        ->and($failedDeployment->logs)->toContain('Deployment could not be started');

    $this->application->update(['docker_registry_image_name' => 'ghcr.io/coollabs/app']);
    queue_application_deployment($this->application->fresh(), 'prr-next-deployment');

    expect(ApplicationDeploymentQueue::where('deployment_uuid', 'prr-next-deployment')->value('status'))->toBe('in_progress');
    Queue::assertPushed(ApplicationDeploymentJob::class, 1);
});
