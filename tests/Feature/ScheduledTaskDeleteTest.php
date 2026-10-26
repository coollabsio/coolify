<?php

use App\Livewire\Project\Shared\ScheduledTask\Executions;
use App\Livewire\Project\Shared\ScheduledTask\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

test('deleting a service scheduled task returns true so the confirmation modal does not refresh the deleted task', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $task = ScheduledTask::factory()->create([
        'team_id' => $this->team->id,
        'service_id' => $service->id,
        'name' => 'nightly',
        'command' => 'echo nightly',
        'frequency' => 'daily',
    ]);

    Livewire::test(Show::class, ['resource' => $service, 'task' => $task])
        ->call('delete')
        ->assertReturned(true)
        ->assertDispatched('scheduledTaskDeleted')
        ->assertNoRedirect();

    expect($task->fresh())->toBeNull();
});

test('deleting an application scheduled task returns true and closes the task modal', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $task = ScheduledTask::factory()->create([
        'team_id' => $this->team->id,
        'application_id' => $application->id,
        'name' => 'nightly',
        'command' => 'echo nightly',
        'frequency' => 'daily',
    ]);

    Livewire::test(Show::class, ['resource' => $application, 'task' => $task])
        ->call('delete')
        ->assertReturned(true)
        ->assertDispatched('scheduledTaskDeleted')
        ->assertNoRedirect();

    expect($task->fresh())->toBeNull();
});

test('the executions poll still works after its task is deleted while the page navigates away', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $task = ScheduledTask::factory()->create([
        'team_id' => $this->team->id,
        'application_id' => $application->id,
    ]);

    $component = Livewire::test(Executions::class, ['taskId' => $task->id]);
    $task->delete();

    $component->call('refreshExecutions')->assertOk();
});
