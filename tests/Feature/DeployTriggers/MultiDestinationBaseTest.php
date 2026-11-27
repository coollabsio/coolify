<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Notification::fake();
    Queue::fake();
    config()->set('cache.default', 'array');

    $this->fixture = deployTriggerFixture();
    $this->application = deployTriggerApplication($this->fixture, ['build_pack' => 'dockerfile']);
    $this->extraServers = collect(['first', 'second'])->map(function (string $name): Server {
        $server = Server::factory()->create(['team_id' => $this->fixture['team']->id, 'name' => "{$name}-extra"]);
        $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
        $this->application->additional_networks()->attach($destination->id, ['server_id' => $server->id]);

        return $server;
    });
    $this->application->refresh();
    $this->commit = str_repeat('d', 40);
    $this->parent = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => new_public_id(),
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'server_id' => $this->fixture['server']->id,
        'destination_id' => $this->fixture['destination']->id,
        'commit' => $this->commit,
        'pull_request_id' => 0,
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function succeedMultiDestinationDeployment(ApplicationDeploymentQueue $deployment, $application, $fixture): void
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();
    foreach ([
        'application' => $application,
        'application_deployment_queue' => $deployment,
        'deployment_uuid' => $deployment->deployment_uuid,
        'server' => $fixture['server'],
        'mainServer' => $fixture['server'],
        'destination' => $fixture['destination'],
        'pull_request_id' => 0,
        'preview' => null,
        'only_this_server' => false,
        'commit' => $deployment->commit,
        'rollback' => false,
    ] as $name => $value) {
        $reflection->getProperty($name)->setValue($job, $value);
    }
    $reflection->getMethod('handleSuccessfulDeployment')->invoke($job);
}

it('does not move the base when a child finishes before the parent queued every destination', function () {
    $child = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => new_public_id(),
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'server_id' => $this->extraServers[0]->id,
        'commit' => $this->commit,
        'pull_request_id' => 0,
        'parent_deployment_uuid' => $this->parent->deployment_uuid,
    ]);

    succeedMultiDestinationDeployment($child, $this->application, $this->fixture);

    expect($this->application->refresh()->trigger_base_commit)->toBeNull();
});

it('moves the base once every destination is queued and finished', function () {
    succeedMultiDestinationDeployment($this->parent, $this->application, $this->fixture);
    $children = ApplicationDeploymentQueue::query()->where('parent_deployment_uuid', $this->parent->deployment_uuid)->get();
    expect($children)->toHaveCount(2)
        ->and($this->application->refresh()->trigger_base_commit)->toBeNull();

    foreach ($children as $child) {
        $child->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);
        succeedMultiDestinationDeployment($child, $this->application, $this->fixture);
    }

    expect($this->application->refresh()->trigger_base_commit)->toBe($this->commit);
});

it('does not move the base when a destination rejected its deployment', function () {
    $this->extraServers[1]->settings->update(['deployment_queue_limit' => 0]);

    succeedMultiDestinationDeployment($this->parent, $this->application, $this->fixture);
    $child = ApplicationDeploymentQueue::query()->where('parent_deployment_uuid', $this->parent->deployment_uuid)->sole();
    $child->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);
    succeedMultiDestinationDeployment($child, $this->application, $this->fixture);

    expect($this->application->refresh()->trigger_base_commit)->toBeNull();
});
