<?php

use App\Models\Application;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();
});

it('returns null when neither application nor service is set', function () {
    $task = ScheduledTask::factory()->create([
        'team_id' => $this->team->id,
    ]);

    expect($task->server())->toBeNull();
});

it('does not throw when accessing dynamic properties on a parentless task', function () {
    $task = ScheduledTask::factory()->create([
        'team_id' => $this->team->id,
    ]);

    expect(fn () => $task->server())->not->toThrow(Exception::class);
});

it('resolves server via application destination', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $task = ScheduledTask::factory()->create([
        'application_id' => $application->id,
        'team_id' => $this->team->id,
    ]);

    expect($task->server()?->id)->toBe($this->server->id);
});

it('resolves server via service destination', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $task = ScheduledTask::factory()->create([
        'service_id' => $service->id,
        'team_id' => $this->team->id,
    ]);

    expect($task->server()?->id)->toBe($this->server->id);
});
