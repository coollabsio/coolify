<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ActivityTypes;
use App\Events\ScheduledTaskDone;
use App\Exceptions\NonReportableException;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Notifications\ScheduledTask\TaskFailed;
use App\Notifications\ScheduledTask\TaskSuccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('redacts remote process output before encoding it for activity storage', function () {
    $activity = new Activity;
    $activity->description = '[]';
    $activity->properties = collect([
        'type' => ActivityTypes::COMMAND->value,
    ]);

    $process = new RunRemoteProcess($activity);
    $encoded = $process->encodeOutput('stderr', 'password=coolify-canary-secret-remote');

    expect($encoded)
        ->not->toContain('coolify-canary-secret-remote')
        ->toContain(REDACTED);
});

it('redacts scheduled task output before it reaches persistent sinks', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);
    EnvironmentVariable::query()->create([
        'key' => 'TASK_VALUE',
        'value' => 'coolify-canary-secret-scheduled',
        'resourceable_type' => $application->getMorphClass(),
        'resourceable_id' => $application->id,
        'is_preview' => false,
    ]);
    $job = new ScheduledTaskJob(new ScheduledTask);
    $job->resource = $application;
    $method = new ReflectionMethod($job, 'redact');

    $redacted = $method->invoke($job, 'unstructured coolify-canary-secret-scheduled output');

    expect($redacted)
        ->not->toContain('coolify-canary-secret-scheduled')
        ->toContain(REDACTED);
});

it('redacts only locked values in scheduled task output when the service allows it', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'redact_all_env_values_in_logs' => false,
    ]);
    foreach (['VISIBLE_VALUE' => ['visible-service-value', false], 'LOCKED_VALUE' => ['coolify-canary-locked-service', true]] as $key => [$value, $locked]) {
        EnvironmentVariable::query()->create([
            'key' => $key,
            'value' => $value,
            'resourceable_type' => $service->getMorphClass(),
            'resourceable_id' => $service->id,
            'is_preview' => false,
            'is_shown_once' => $locked,
        ]);
    }
    $job = new ScheduledTaskJob(new ScheduledTask);
    $job->resource = $service;

    $redacted = (new ReflectionMethod($job, 'redact'))->invoke($job, 'visible-service-value coolify-canary-locked-service');

    expect($redacted)->toBe('visible-service-value '.REDACTED);
});

it('keeps scheduled task exceptions whose messages need no redaction', function () {
    $job = new ScheduledTaskJob(new ScheduledTask);
    $exception = new NonReportableException('No valid container was found.');

    $result = (new ReflectionMethod($job, 'redactedException'))->invoke($job, $exception, $exception->getMessage());

    expect($result)->toBe($exception);
});

it('rebuilds redacted scheduled task exceptions with the same reportability', function () {
    $job = new ScheduledTaskJob(new ScheduledTask);
    $method = new ReflectionMethod($job, 'redactedException');
    $secret = 'password=coolify-canary-secret-exception';

    $nonReportable = $method->invoke($job, new NonReportableException($secret, 3), 'password='.REDACTED);
    $reportable = $method->invoke($job, new RuntimeException($secret, 4), 'password='.REDACTED);

    expect($nonReportable)->toBeInstanceOf(NonReportableException::class)
        ->and($nonReportable->getMessage())->toBe('password='.REDACTED)
        ->and($nonReportable->getCode())->toBe(3)
        ->and($nonReportable->getPrevious())->toBeNull()
        ->and($reportable)->toBeInstanceOf(RuntimeException::class)
        ->and($reportable->getPrevious())->toBeNull();
});

/**
 * A running service container with an environment value that must not reach any scheduled task sink.
 *
 * @return array{0: ScheduledTask, 1: Team}
 */
function makeScheduledTaskRedactionFixture(string $secret): array
{
    Server::flushIdentityMap();
    Notification::fake();
    Event::fake([ScheduledTaskDone::class]);
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false]);

    $team = Team::factory()->create();
    $team->emailNotificationSettings->update([
        'smtp_enabled' => true,
        'scheduled_task_success_email_notifications' => true,
        'scheduled_task_failure_email_notifications' => true,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    ServiceApplication::create(['name' => 'app', 'service_id' => $service->id, 'status' => 'running:healthy']);
    EnvironmentVariable::query()->create([
        'key' => 'TASK_SECRET',
        'value' => $secret,
        'resourceable_type' => $service->getMorphClass(),
        'resourceable_id' => $service->id,
        'is_preview' => false,
    ]);

    $task = ScheduledTask::factory()->create([
        'team_id' => $team->id,
        'service_id' => $service->id,
        'command' => 'print-secret',
    ]);

    return [$task, $team];
}

it('redacts scheduled task output in the execution log and success notification', function () {
    $secret = 'coolify-canary-task-success-output';
    [$task, $team] = makeScheduledTaskRedactionFixture($secret);
    Process::fake(fn () => Process::result(output: "value {$secret}"));

    (new ScheduledTaskJob($task))->handle();

    expect($task->executions()->sole())
        ->status->toBe('success')
        ->message->toBe('value '.REDACTED);
    Notification::assertSentTo($team, TaskSuccess::class, fn (TaskSuccess $notification): bool => $notification->output === 'value '.REDACTED);
});

it('redacts scheduled task failures in the execution log, error log, and rethrown exception', function () {
    $secret = 'coolify-canary-task-failure-output';
    [$task] = makeScheduledTaskRedactionFixture($secret);
    Process::fake(fn () => Process::result(errorOutput: "failed near {$secret}", exitCode: 1));
    $errorChannel = Mockery::mock();
    $errorChannel->shouldReceive('error')->once()->withArgs(fn (string $message, array $context): bool => ! str_contains(serialize($context), $secret));
    Log::shouldReceive('channel')->with('scheduled-errors')->andReturn($errorChannel);

    try {
        (new ScheduledTaskJob($task))->handle();
        $thrown = null;
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toBe('failed near '.REDACTED)
        ->and($thrown->getPrevious())->toBeNull()
        ->and($task->executions()->sole())
        ->status->toBe('failed')
        ->message->toBe('failed near '.REDACTED);
});

it('redacts permanent scheduled task failures in the execution, error log, and failure notification', function () {
    $secret = 'coolify-canary-task-permanent-failure';
    [$task, $team] = makeScheduledTaskRedactionFixture($secret);
    $job = new ScheduledTaskJob($task);
    ScheduledTaskExecution::forceCreate(['uuid' => $job->executionUuid, 'scheduled_task_id' => $task->id, 'started_at' => now()]);
    $errorChannel = Mockery::mock();
    $errorChannel->shouldReceive('error')->once()->withArgs(fn (string $message, array $context): bool => ! str_contains(serialize($context), $secret));
    Log::shouldReceive('channel')->with('scheduled-errors')->andReturn($errorChannel);

    $job->failed(new RuntimeException("password leaked {$secret}"));

    $execution = ScheduledTaskExecution::query()->where('uuid', $job->executionUuid)->sole();
    expect($execution->status)->toBe('failed')
        ->and($execution->message)->toContain('password leaked '.REDACTED)
        ->and($execution->message.$execution->error_details)->not->toContain($secret);
    Notification::assertSentTo($team, TaskFailed::class, fn (TaskFailed $notification): bool => $notification->output === 'password leaked '.REDACTED);
});

it('records scheduled tasks that print literal null as successful with empty output', function () {
    [$task, $team] = makeScheduledTaskRedactionFixture('coolify-canary-null-output');
    Process::fake(fn () => Process::result(output: "null\n"));

    (new ScheduledTaskJob($task))->handle();

    expect($task->executions()->sole())
        ->status->toBe('success')
        ->message->toBe('');
    Notification::assertSentTo($team, TaskSuccess::class, fn (TaskSuccess $notification): bool => $notification->output === '');
});
