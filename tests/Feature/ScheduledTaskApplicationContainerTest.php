<?php

use App\Events\ScheduledTaskDone;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Event::fake([ScheduledTaskDone::class]);
    Notification::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false]);

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false, 'docker_version' => '28.0.0']);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);

    $this->application = Application::factory()->create([
        'environment_id' => Environment::factory()->create(['project_id' => $project->id])->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->task = ScheduledTask::factory()->create([
        'team_id' => $team->id,
        'application_id' => $this->application->id,
        'container' => null,
        'command' => 'echo hello',
    ]);
});

/**
 * @param  array<string, string>  $containers  container name => docker state
 */
function fakeApplicationContainers(array $containers): void
{
    $output = collect($containers)
        ->map(fn (string $state, string $name) => json_encode(['ID' => $name, 'Names' => $name, 'Labels' => 'coolify.applicationId=1', 'State' => $state]))
        ->implode("\n");

    Process::fake(fn ($process) => str_contains($process->command, 'docker ps -a --filter')
        ? Process::result(output: $output)
        : Process::result(output: 'done'));
}

test('a scheduled task ignores stopped application containers', function () {
    $uuid = $this->application->uuid;
    fakeApplicationContainers(["{$uuid}-111111111111" => 'exited', "{$uuid}-222222222222" => 'running']);

    (new ScheduledTaskJob($this->task))->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, "docker exec '{$uuid}-222222222222' sh -c"));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, "docker exec '{$uuid}-111111111111'"));
    expect($this->task->executions()->sole()->status)->toBe('success');
});

test('a scheduled task runs in the application container with the exact name', function () {
    $uuid = $this->application->uuid;
    fakeApplicationContainers(["{$uuid}-111111111111" => 'running', "{$uuid}-222222222222" => 'running']);
    $this->task->update(['container' => "{$uuid}-222222222222"]);

    (new ScheduledTaskJob($this->task->refresh()))->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, "docker exec '{$uuid}-222222222222' sh -c"));
    expect($this->task->executions()->sole()->status)->toBe('success');
});
