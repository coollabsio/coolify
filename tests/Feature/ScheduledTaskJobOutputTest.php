<?php

use App\Events\ScheduledTaskDone;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function scheduledTaskJobOutputPrivateKey(): string
{
    return "-----BEGIN OPENSSH PRIVATE KEY-----\n".
        "b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW\n".
        "QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk\n".
        "hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA\n".
        "AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV\n".
        "uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==\n".
        '-----END OPENSSH PRIVATE KEY-----';
}

it('stores stderr from successful scheduled task executions', function () {
    Event::fake([ScheduledTaskDone::class]);
    Notification::fake();
    Process::fake();
    Storage::fake('ssh-keys');
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $privateKey = PrivateKey::create([
        'name' => 'scheduled-task-output-key',
        'private_key' => scheduledTaskJobOutputPrivateKey(),
        'team_id' => $team->id,
    ]);
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $task = ScheduledTask::factory()->create([
        'application_id' => $application->id,
        'team_id' => $team->id,
        'command' => 'echo logging to stdout; echo logging to stderr >&2',
    ]);

    $containerName = 'scheduled-task-container';
    $containerOutput = json_encode([
        'Names' => "/{$containerName}",
        'Labels' => "coolify.applicationId={$application->id}",
    ]).PHP_EOL;

    Process::fake(function ($process) use ($application, $containerName, $containerOutput) {
        if (str_contains($process->command, "label=coolify.applicationId={$application->id}")) {
            return Process::result(output: $containerOutput);
        }

        if (str_contains($process->command, "docker exec {$containerName}")) {
            return Process::result(
                output: "logging to stdout\n",
                errorOutput: "logging to stderr\n",
            );
        }

        return Process::result();
    });

    (new ScheduledTaskJob($task))->handle();

    $execution = ScheduledTaskExecution::where('scheduled_task_id', $task->id)->first();

    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe('success')
        ->and($execution->message)->toContain('logging to stdout')
        ->and($execution->message)->toContain('logging to stderr');
});
