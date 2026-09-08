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
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Livewire\Exceptions\MethodNotFoundException;
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

afterEach(function () {
    Event::forget(RouteMatched::class);
});

function bindApplicationScheduledTaskRoute(Application $application, ScheduledTask $task): void
{
    $application->loadMissing('environment.project');

    Event::listen(RouteMatched::class, function (RouteMatched $event) use ($application, $task): void {
        $event->route->setParameter('task_uuid', $task->uuid);
        $event->route->setParameter('project_uuid', $application->environment->project->uuid);
        $event->route->setParameter('environment_uuid', $application->environment->uuid);
        $event->route->setParameter('application_uuid', $application->uuid);
    });
}

function originalScheduledTaskState(ScheduledTask $task): array
{
    return $task->only(['name', 'command', 'frequency', 'timeout', 'enabled', 'container']);
}

test('read-only member can view an existing scheduled task', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);
    bindApplicationScheduledTaskRoute($this->application, $this->task);

    Livewire::test(Show::class)
        ->assertSuccessful()
        ->assertSet('name', 'validate-benign')
        ->assertSet('command', 'echo scheduled-ok')
        ->assertSet('frequency', '0 0 * * *');
});

test('the private syncData helper is not remotely callable through Livewire', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);
    bindApplicationScheduledTaskRoute($this->application, $this->task);

    $original = originalScheduledTaskState($this->task);

    $component = Livewire::test(Show::class)
        ->set('name', 'member-edited')
        ->set('command', 'echo member-edited')
        ->set('frequency', '* * * * *')
        ->set('timeout', 120)
        ->set('isEnabled', true);

    expect(fn () => $component->call('syncData', true))
        ->toThrow(MethodNotFoundException::class);

    expect(originalScheduledTaskState($this->task->fresh()))->toBe($original);
});

test('read-only member cannot persist scheduled task changes through submit', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);
    bindApplicationScheduledTaskRoute($this->application, $this->task);

    $original = originalScheduledTaskState($this->task);

    Livewire::test(Show::class)
        ->set('name', 'member-edited')
        ->set('command', 'echo member-edited')
        ->set('frequency', '* * * * *')
        ->call('submit');

    expect(originalScheduledTaskState($this->task->fresh()))->toBe($original);
});

test('the private saveScheduledTask helper is not remotely callable through Livewire', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(Add::class, [
        'id' => (string) $this->application->id,
        'type' => 'application',
        'containerNames' => collect(),
    ])
        ->set('name', 'member-created')
        ->set('command', 'id')
        ->set('frequency', '* * * * *')
        ->set('timeout', 300);

    expect(fn () => $component->call('saveScheduledTask'))
        ->toThrow(MethodNotFoundException::class);

    expect(ScheduledTask::query()->where('name', 'member-created')->exists())->toBeFalse();
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
    bindApplicationScheduledTaskRoute($this->application, $this->task);

    Livewire::test(Show::class)
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
