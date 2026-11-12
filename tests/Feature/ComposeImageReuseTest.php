<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

const REUSE_COMMIT = '0123456789abcdef0123456789abcdef01234567';
const REUSE_FINGERPRINT = 'current-fingerprint';

class ComposeImageReuseDeploymentJob extends ApplicationDeploymentJob
{
    /** @var array<int, string> */
    public array $commands = [];

    /** @var array<int, string> */
    public array $logEntries = [];

    public string $inspectOutput = REUSE_FINGERPRINT."\n".REUSE_FINGERPRINT;

    public function __construct() {}

    public function execute_remote_command(...$commands)
    {
        $savedOutputs = (new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs'))->getValue($this);
        foreach ($commands as $command) {
            $this->commands[] = $command[0];
            if (isset($command['save'])) {
                $savedOutputs->put($command['save'], $this->inspectOutput);
            }
        }
    }
}

function composeReuseApplication(string $compose): Application
{
    return Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ])->fresh();
}

function composeReuseJob(Application $application, array $properties = []): ComposeImageReuseDeploymentJob
{
    $job = new ComposeImageReuseDeploymentJob;
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message) use ($job) {
        $job->logEntries[] = $message;
    });

    foreach (array_merge([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'saved_outputs' => collect(),
        'commit' => REUSE_COMMIT,
        'pull_request_id' => 0,
        'force_rebuild' => false,
        'use_build_server' => false,
        'docker_compose_custom_build_command' => null,
        'docker_compose_build_fingerprint' => REUSE_FINGERPRINT,
        'coolify_variables' => 'COOLIFY_RESOURCE_UUID=abc ',
    ], $properties) as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    return $job;
}

function canReuseComposeImages(ComposeImageReuseDeploymentJob $job, Application $application): bool
{
    $composeFile = $application->parse(commit: REUSE_COMMIT);

    return (new ReflectionMethod(ApplicationDeploymentJob::class, 'can_reuse_docker_compose_images'))->invoke($job, $composeFile);
}

function composeBuildFingerprint(Application $application, array $buildEnvironment = ['APP_URL=https://a.example.com']): string
{
    return (new ReflectionMethod(ApplicationDeploymentJob::class, 'docker_compose_build_fingerprint'))
        ->invoke(composeReuseJob($application->fresh()), collect($buildEnvironment));
}

function addComposeEnvironmentVariable(Application $application, string $key, bool $isBuildtime): void
{
    EnvironmentVariable::create([
        'key' => $key,
        'value' => 'value',
        'is_buildtime' => $isBuildtime,
        'is_runtime' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Process::fake(['*' => Process::result(output: '')]);
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $this->environment = Project::factory()->create(['team_id' => $team->id])->environments()->first();
    $this->compose = "services:\n  web:\n    build: .\n  worker:\n    build: ./worker\n  cache:\n    image: redis:7\n";
});

it('reuses the commit images when every image was built from the current inputs', function () {
    $application = composeReuseApplication($this->compose);
    $job = composeReuseJob($application);

    expect(canReuseComposeImages($job, $application))->toBeTrue()
        ->and($job->commands)->toBe([
            "docker image inspect --format '{{ index .Config.Labels \"coolify.build-fingerprint\" }}' '{$application->uuid}_web:".REUSE_COMMIT."' '{$application->uuid}_worker:".REUSE_COMMIT."' 2>/dev/null",
        ])
        ->and($job->logEntries)->toBe(['Images found with the same Git Commit SHA and build inputs. Build step skipped.']);
});

it('builds when an image was built from other inputs', function () {
    $application = composeReuseApplication($this->compose);
    $job = composeReuseJob($application);
    $job->inspectOutput = REUSE_FINGERPRINT."\nold-fingerprint";

    expect(canReuseComposeImages($job, $application))->toBeFalse();
});

it('builds when an image has no fingerprint label', function () {
    $application = composeReuseApplication($this->compose);
    $job = composeReuseJob($application);
    $job->inspectOutput = REUSE_FINGERPRINT."\n";

    expect(canReuseComposeImages($job, $application))->toBeFalse();
});

it('builds when an image is missing on the server', function () {
    $application = composeReuseApplication($this->compose);
    $job = composeReuseJob($application);
    $job->inspectOutput = REUSE_FINGERPRINT;

    expect(canReuseComposeImages($job, $application))->toBeFalse();
});

it('builds when a built service sets its own image name', function () {
    $application = composeReuseApplication("services:\n  web:\n    build: .\n    image: example/web:latest\n");
    $job = composeReuseJob($application);

    expect(canReuseComposeImages($job, $application))->toBeFalse()
        ->and($job->commands)->toBe([]);
});

it('builds without checking images when the deployment cannot reuse a commit image', function (array $properties) {
    $application = composeReuseApplication($this->compose);
    $job = composeReuseJob($application, $properties);

    expect(canReuseComposeImages($job, $application))->toBeFalse()
        ->and($job->commands)->toBe([]);
})->with([
    'forced rebuild' => [['force_rebuild' => true]],
    'unresolved commit' => [['commit' => 'HEAD']],
    'custom build command' => [['docker_compose_custom_build_command' => 'docker compose build']],
    'build server' => [['use_build_server' => true]],
    'no fingerprint' => [['docker_compose_build_fingerprint' => null]],
]);

it('keeps the fingerprint when only a runtime variable changes', function () {
    $application = composeReuseApplication($this->compose);
    $before = composeBuildFingerprint($application);

    addComposeEnvironmentVariable($application, 'RUNTIME_ONLY', isBuildtime: false);

    expect(composeBuildFingerprint($application))->toBe($before);
});

it('changes the fingerprint when a build input changes', function (Closure $change, array $buildEnvironment) {
    $application = composeReuseApplication($this->compose);
    $before = composeBuildFingerprint($application);

    $change($application);

    expect(composeBuildFingerprint($application, $buildEnvironment))->not->toBe($before);
})->with([
    'build-time variable' => [fn (Application $application) => addComposeEnvironmentVariable($application, 'BUILD_VALUE', isBuildtime: true), ['APP_URL=https://a.example.com']],
    'build setting' => [fn (Application $application) => $application->settings()->update(['inject_build_args_to_dockerfile' => false]), ['APP_URL=https://a.example.com']],
    'resolved build value such as a domain or shared variable' => [fn () => null, ['APP_URL=https://b.example.com']],
]);

it('adds the fingerprint label to every service with a build section', function () {
    $job = composeReuseJob(composeReuseApplication($this->compose));

    $composeFile = (new ReflectionMethod(ApplicationDeploymentJob::class, 'add_build_fingerprint_to_compose'))->invoke($job, [
        'services' => [
            'web' => ['build' => '.'],
            'api' => ['build' => ['context' => './api', 'labels' => ['team' => 'core']]],
            'worker' => ['build' => ['context' => './worker', 'labels' => ['team=core']]],
            'cache' => ['image' => 'redis:7'],
        ],
    ]);

    expect($composeFile['services'])->toBe([
        'web' => ['build' => ['context' => '.', 'labels' => ['coolify.build-fingerprint='.REUSE_FINGERPRINT]]],
        'api' => ['build' => ['context' => './api', 'labels' => ['team' => 'core', 'coolify.build-fingerprint' => REUSE_FINGERPRINT]]],
        'worker' => ['build' => ['context' => './worker', 'labels' => ['team=core', 'coolify.build-fingerprint='.REUSE_FINGERPRINT]]],
        'cache' => ['image' => 'redis:7'],
    ]);
});
