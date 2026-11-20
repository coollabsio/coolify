<?php

use App\Livewire\Project\Shared\ScheduledTask\Add;
use App\Livewire\Project\Shared\ScheduledTask\Show;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();

    $this->owner = User::factory()->create();
    $this->owner->teams()->attach($this->team, ['role' => 'owner']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->task = ScheduledTask::factory()->create([
        'team_id' => $this->team->id,
        'application_id' => $this->application->id,
        'name' => 'validate-benign',
        'command' => 'echo scheduled-ok',
        'frequency' => '0 0 * * *',
        'timeout' => 300,
        'enabled' => true,
    ]);
});

/**
 * Mount the task modal the way the scheduled-task list page does: the parent
 * `All` component passes the resource and the selected task as props.
 */
function mountScheduledTaskShow(Application $application, ScheduledTask $task): Testable
{
    return Livewire::test(Show::class, ['resource' => $application, 'task' => $task]);
}

function originalScheduledTaskState(ScheduledTask $task): array
{
    return $task->only(['name', 'command', 'frequency', 'timeout', 'enabled', 'container']);
}

test('read-only member can view an existing scheduled task', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    mountScheduledTaskShow($this->application, $this->task)
        ->assertSuccessful()
        ->assertSet('name', 'validate-benign')
        ->assertSet('command', 'Hidden (only admins can view)')
        ->assertSet('frequency', '0 0 * * *');
});

test('read-only member cannot persist scheduled task changes through submit', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $original = originalScheduledTaskState($this->task);

    mountScheduledTaskShow($this->application, $this->task)
        ->set('name', 'member-edited')
        ->set('command', 'echo member-edited')
        ->set('frequency', '* * * * *')
        ->call('submit');

    expect(originalScheduledTaskState($this->task->fresh()))->toBe($original);
});

test('read-only member cannot create a scheduled task through submit', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Add::class, [
        'id' => (string) $this->application->id,
        'type' => 'application',
        'containerNames' => collect(),
    ])
        ->set('name', 'member-created')
        ->set('command', 'id')
        ->set('frequency', '* * * * *')
        ->set('timeout', 300)
        ->call('submit');

    expect(ScheduledTask::query()->where('name', 'member-created')->exists())->toBeFalse();
});

test('admin can persist scheduled task changes through submit', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    mountScheduledTaskShow($this->application, $this->task)
        ->set('name', 'updated-by-admin')
        ->set('command', 'echo admin-ok')
        ->set('frequency', '0 1 * * *')
        ->set('timeout', 600)
        ->call('submit')
        ->assertSuccessful();

    $this->task->refresh();

    expect($this->task->name)->toBe('updated-by-admin')
        ->and($this->task->command)->toBe('echo admin-ok')
        ->and($this->task->frequency)->toBe('0 1 * * *')
        ->and($this->task->timeout)->toBe(600);
});
