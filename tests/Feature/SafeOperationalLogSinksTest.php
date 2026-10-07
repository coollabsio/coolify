<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ActivityTypes;
use App\Exceptions\NonReportableException;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('routes scheduled task stored output notifications and failures through redaction', function () {
    $source = file_get_contents(app_path('Jobs/ScheduledTaskJob.php'));

    expect($source)
        ->toContain('$this->task_output = $this->redact(instant_remote_process(')
        ->toContain("'message' => \$this->task_output ?? \$safeErrorMessage")
        ->toContain("'error_details' => \$safeTrace")
        ->toContain('new TaskSuccess($this->task, $this->task_output)')
        ->toContain('new TaskFailed($this->task, $safeErrorMessage)')
        ->not->toContain("'error' => \$exception?->getMessage()")
        ->toContain('throw $this->redactedException($e, $safeErrorMessage);')
        ->not->toContain("'trace' => \$exception?->getTraceAsString()");
});
