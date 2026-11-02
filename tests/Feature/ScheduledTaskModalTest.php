<?php

use App\Livewire\Project\Shared\ScheduledTask\All;
use App\Livewire\Project\Shared\ScheduledTask\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->task = ScheduledTask::factory()->create([
        'team_id' => $this->team->id,
        'application_id' => $this->application->id,
        'name' => 'modal-task',
        'command' => 'echo hello',
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('opening a task shows the editor modal', function () {
    Livewire::test(All::class, ['resource' => $this->application])
        ->call('openTask', $this->task->uuid)
        ->assertSet('taskModalOpen', true)
        ->assertSet('taskUuid', $this->task->uuid)
        ->assertSeeLivewire(Show::class);
});

test('task uuid in the url opens the modal on load', function () {
    Livewire::withQueryParams(['task_uuid' => $this->task->uuid])
        ->test(All::class, ['resource' => $this->application])
        ->assertSet('taskModalOpen', true)
        ->assertSeeLivewire(Show::class);
});

test('a task of another resource cannot be opened', function () {
    $otherTask = ScheduledTask::factory()->create(['team_id' => Team::factory()->create()->id]);

    expect(fn () => Livewire::test(All::class, ['resource' => $this->application])
        ->call('openTask', $otherTask->uuid))
        ->toThrow(ModelNotFoundException::class);
});

test('deleting a task from the modal closes it', function () {
    $list = Livewire::test(All::class, ['resource' => $this->application])
        ->call('openTask', $this->task->uuid);

    Livewire::test(Show::class, ['resource' => $this->application, 'task' => $this->task])
        ->call('delete')
        ->assertDispatched('scheduledTaskDeleted');

    expect(ScheduledTask::find($this->task->id))->toBeNull();

    $list->dispatch('scheduledTaskDeleted')
        ->assertSet('taskModalOpen', false)
        ->assertSet('taskUuid', '')
        ->assertDontSee('modal-task');
});

test('old task page url redirects to the list with the modal open', function () {
    $application = $this->application->load('environment.project');

    $this->get(route('project.application.scheduled-tasks', [
        'project_uuid' => $application->environment->project->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
        'task_uuid' => $this->task->uuid,
    ]))->assertRedirect(route('project.application.scheduled-tasks.show', [
        'project_uuid' => $application->environment->project->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
        'task_uuid' => $this->task->uuid,
    ]));
});
