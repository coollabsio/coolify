<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Team;
use App\Traits\ExecuteRemoteCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSafeDeploymentLogFixture(): array
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->uuid(),
    ]);

    return [$application, $deployment];
}

function createSafeDeploymentLogVariable(Application $application, string $key, string $value, bool $locked): void
{
    EnvironmentVariable::query()->create([
        'key' => $key,
        'value' => $value,
        'resourceable_type' => $application->getMorphClass(),
        'resourceable_id' => $application->id,
        'is_preview' => false,
        'is_shown_once' => $locked,
    ]);
}

it('redacts every application environment value before storing deployment logs', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);
    $secret = 'coolify-canary-normal-environment-secret';

    EnvironmentVariable::query()->create([
        'key' => 'NORMAL_ENVIRONMENT_VALUE',
        'value' => $secret,
        'resourceable_type' => $application->getMorphClass(),
        'resourceable_id' => $application->id,
        'is_preview' => false,
        'is_shown_once' => false,
    ]);

    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->uuid(),
    ]);

    $deployment->addLogEntry(
        message: "process returned {$secret}",
        command: "deploy --credential={$secret}",
    );

    $storedLogs = $deployment->refresh()->getRawOriginal('logs');

    expect($storedLogs)
        ->not->toContain($secret)
        ->toContain(REDACTED);
});

it('redacts only locked environment values when the application allows it', function () {
    [$application, $deployment] = makeSafeDeploymentLogFixture();
    $application->settings->update(['redact_all_env_values_in_logs' => false]);
    createSafeDeploymentLogVariable($application, 'APP_ENV_NAME', 'visible-production-value', locked: false);
    createSafeDeploymentLogVariable($application, 'LOCKED_VALUE', 'coolify-canary-locked-value', locked: true);

    $deployment->addLogEntry('env visible-production-value coolify-canary-locked-value');

    $output = json_decode($deployment->refresh()->logs, true)[0]['output'];

    expect($output)->toBe('env visible-production-value '.REDACTED);
});

it('keeps redacting all values for other applications in the same team', function () {
    [$application, $deployment] = makeSafeDeploymentLogFixture();
    createSafeDeploymentLogVariable($application, 'APP_ENV_NAME', 'hidden-production-value', locked: false);
    $otherApplication = Application::factory()->create(['environment_id' => $application->environment_id]);
    $otherApplication->settings->update(['redact_all_env_values_in_logs' => false]);

    $deployment->addLogEntry('env hidden-production-value');

    expect($application->settings->fresh()->redact_all_env_values_in_logs)->toBeTrue()
        ->and(json_decode($deployment->refresh()->logs, true)[0]['output'])->toBe('env '.REDACTED);
});

it('redacts extra known secrets passed with a log entry', function () {
    [, $deployment] = makeSafeDeploymentLogFixture();

    $deployment->addLogEntry(
        message: 'output coolify-canary-remote-secret',
        command: 'echo coolify-canary-remote-secret',
        knownSecrets: ['coolify-canary-remote-secret'],
    );

    expect($deployment->refresh()->getRawOriginal('logs'))
        ->not->toContain('coolify-canary-remote-secret')
        ->toContain(REDACTED);
});

it('passes remote secrets from the deployment job into stored log entries', function () {
    [$application, $deployment] = makeSafeDeploymentLogFixture();
    $job = new class
    {
        use ExecuteRemoteCommand;

        public $application;

        public $application_deployment_queue;

        public ?array $remote_secrets_cache = ['API_TOKEN' => 'coolify-canary-vault-secret'];
    };
    $job->application = $application;
    $job->application_deployment_queue = $deployment;

    (new ReflectionMethod($job, 'addRetryLogEntry'))->invoke($job, 1, 3, 1, 'failed near coolify-canary-vault-secret');

    expect($deployment->refresh()->getRawOriginal('logs'))
        ->not->toContain('coolify-canary-vault-secret')
        ->toContain(REDACTED);
});

it('starts a new log list when stored logs are corrupted', function () {
    [, $deployment] = makeSafeDeploymentLogFixture();
    ApplicationDeploymentQueue::query()->whereKey($deployment->id)->update(['logs' => '{not json']);

    $deployment->addLogEntry('fresh entry');

    $logs = json_decode($deployment->refresh()->logs, true);

    expect($logs)->toHaveCount(1)
        ->and($logs[0]['output'])->toBe('fresh entry')
        ->and($logs[0]['order'])->toBe(1);
});

it('routes each REST container log endpoint through the shared redactor', function (string $controller) {
    $source = file_get_contents(app_path("Http/Controllers/Api/{$controller}.php"));

    expect($source)->toContain('sanitizeLogsForExport(getContainerLogs(');
})->with([
    'applications' => 'ApplicationsController',
    'databases' => 'DatabasesController',
    'services' => 'ServicesController',
    'service applications' => 'ServiceApplicationsController',
    'service databases' => 'ServiceDatabasesController',
]);
