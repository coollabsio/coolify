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
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0]);
    Event::fake([ScheduledTaskDone::class]);
    Notification::fake();
});

function createApplicationTask(array $application = [], array $task = []): ScheduledTask
{
    $team = Team::factory()->create();
    $privateKeyContent = '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----';
    $privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => $privateKeyContent,
        'team_id' => $team->id,
    ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKeyContent);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'status' => 'running',
        ...$application,
    ]);

    return ScheduledTask::factory()->create([
        'team_id' => $team->id,
        'application_id' => $application->id,
        'command' => 'php artisan schedule:run',
        ...$task,
    ]);
}

function dockerPsLine(string $name, string $createdAt, string $status): string
{
    return json_encode([
        'ID' => $name,
        'Names' => $name,
        'CreatedAt' => "{$createdAt} +0000 UTC",
        'State' => 'running',
        'Status' => $status,
    ]);
}

it('runs the task in the serving container while a rolling update has two running', function () {
    $task = createApplicationTask();
    Process::fake([
        '*docker ps*' => Process::result(output: implode(PHP_EOL, [
            dockerPsLine('app-new', '2026-09-23 16:18:53', 'Up 8 seconds (health: starting)'),
            dockerPsLine('app-old', '2026-09-23 16:00:00', 'Up 18 minutes (healthy)'),
        ])),
        '*docker exec*' => Process::result(output: 'done'),
    ]);

    (new ScheduledTaskJob($task))->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, "docker exec 'app-old' "));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "docker exec 'app-new' "));
    expect($task->executions()->latest('id')->first()->status)->toBe('success');
});

it('keeps running the task in the named service of a docker compose application', function () {
    $task = createApplicationTask(['build_pack' => 'dockercompose'], ['container' => 'worker']);
    $uuid = $task->application->uuid;
    Process::fake([
        '*docker ps*' => Process::result(output: implode(PHP_EOL, [
            dockerPsLine("web-{$uuid}", '2026-09-23 16:18:53', 'Up 8 minutes (healthy)'),
            dockerPsLine("worker-{$uuid}", '2026-09-23 16:00:00', 'Up 18 minutes (healthy)'),
        ])),
        '*docker exec*' => Process::result(output: 'done'),
    ]);

    (new ScheduledTaskJob($task))->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, "docker exec 'worker-{$uuid}' "));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "docker exec 'web-{$uuid}' "));
});
