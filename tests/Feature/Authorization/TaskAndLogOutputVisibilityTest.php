<?php

use App\Livewire\Project\Shared\GetLogs;
use App\Livewire\Project\Shared\ScheduledTask\Executions;
use App\Livewire\Project\Shared\ScheduledTask\Show;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    Event::forget(RouteMatched::class);
});

beforeEach(function () {
    Process::fake();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);
    $this->team->members()->attach($this->member, ['role' => 'member']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $this->server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $project->environments()->firstOrFail();

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->task = ScheduledTask::factory()->create([
        'command' => 'backup --token stored-task-command-value',
        'application_id' => $this->application->id,
        'team_id' => $this->team->id,
    ]);
    $this->execution = ScheduledTaskExecution::create([
        'scheduled_task_id' => $this->task->id,
        'status' => 'success',
        'message' => 'stored-task-output-value',
    ]);
});

function outputVisibilityToken(User $user, Team $team, array $abilities): string
{
    session(['currentTeam' => $team]);
    $token = $user->createToken('output-visibility', $abilities);
    DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['team_id' => $team->id]);

    return $token->plainTextToken;
}

function scheduledTaskComponents(): array
{
    Event::listen(RouteMatched::class, function (RouteMatched $event): void {
        $event->route->setParameter('task_uuid', test()->task->uuid);
        $event->route->setParameter('project_uuid', test()->environment->project->uuid);
        $event->route->setParameter('environment_uuid', test()->environment->uuid);
        $event->route->setParameter('application_uuid', test()->application->uuid);
    });

    return [
        Livewire::test(Show::class),
        Livewire::test(Executions::class, ['taskId' => test()->task->id])->call('selectTask', test()->execution->id),
    ];
}

it('hides task commands, task output, and logs from a read token', function () {
    $headers = ['Authorization' => 'Bearer '.outputVisibilityToken($this->member, $this->team, ['read'])];

    $tasks = $this->withHeaders($headers)->getJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks")->assertOk();
    $executions = $this->withHeaders($headers)->getJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks/{$this->task->uuid}/executions")->assertOk();

    expect($tasks->getContent().$executions->getContent())
        ->not->toContain('stored-task-command-value')
        ->not->toContain('stored-task-output-value')
        ->and($tasks->json('0.name'))->toBe($this->task->name)
        ->and($executions->json('0.status'))->toBe('success');

    $this->withHeaders($headers)->getJson("/api/v1/applications/{$this->application->uuid}/logs")->assertForbidden();
});

it('returns task commands and task output to a read sensitive token', function () {
    $headers = ['Authorization' => 'Bearer '.outputVisibilityToken($this->owner, $this->team, ['read', 'read:sensitive'])];

    $this->withHeaders($headers)->getJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks")
        ->assertOk()
        ->assertJsonPath('0.command', 'backup --token stored-task-command-value');
    $this->withHeaders($headers)->getJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks/{$this->task->uuid}/executions")
        ->assertOk()
        ->assertJsonPath('0.message', 'stored-task-output-value');
});

it('hides task commands, task output, and logs from a member in the ui', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    foreach (scheduledTaskComponents() as $component) {
        expect(json_encode($component->snapshot).$component->html())
            ->not->toContain('stored-task-command-value')
            ->not->toContain('stored-task-output-value');
    }

    Livewire::test(GetLogs::class, ['server' => $this->server, 'resource' => $this->application, 'container' => 'app-container'])
        ->assertSee('Hidden (only admins can view)')
        ->call('getLogs')
        ->assertReturned('Unauthorized.');

    Process::assertNothingRan();
});

it('shows task commands and task output to an owner in the ui', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    [$show, $executions] = scheduledTaskComponents();

    $show->assertSet('command', 'backup --token stored-task-command-value');
    $executions->assertSee('stored-task-output-value');

    Livewire::test(GetLogs::class, ['server' => $this->server, 'resource' => $this->application, 'container' => 'app-container'])
        ->assertDontSee('Hidden (only admins can view)');
});

it('renders logs inside one root element so the parent can re-render it', function (string $role) {
    $this->actingAs($role === 'owner' ? $this->owner : $this->member);
    session(['currentTeam' => $this->team]);

    $html = Livewire::test(GetLogs::class, ['server' => $this->server, 'resource' => $this->application, 'container' => 'app-container'])
        ->html();

    // Livewire reuses the first tag name as the placeholder for kept children; a leading comment gives an empty tag.
    preg_match('/<([a-zA-Z0-9\-]*)/', $html, $matches);

    expect($matches[1])->toBe('div');
})->with(['owner', 'member']);
