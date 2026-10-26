<?php

use App\Actions\Application\GetRollbackImages;
use App\Actions\ImageScan\RefreshCandidates;
use App\Actions\ImageScan\ScanImage;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanStatus;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\RefreshCandidatesJob;
use App\Jobs\ScanResourceImagesJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\ImageScan;
use App\Models\ImageScanPolicy;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ImageScan\ResourceScans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

const GATE_IMAGE_ID = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const GATE_REPO_DIGEST = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

const GATE_COMMIT = '0123456789abcdef0123456789abcdef01234567';

/**
 * Thrown when the job reaches the container start, so a test can stop there.
 */
class DeploymentGateStarted extends RuntimeException {}

/**
 * Records the deployment commands instead of running them. `outputs` maps a part of a command to its output.
 */
class DeploymentGateTestJob extends ApplicationDeploymentJob
{
    /** @var list<string> */
    public array $commands = [];

    /** @var array<string, string> */
    public array $outputs = [];

    public function __construct() {}

    public function execute_remote_command(...$commands): void
    {
        $saved = (new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs'))->getValue($this);

        foreach ($commands as $command) {
            $text = (string) ($command['command'] ?? $command[0]);
            $this->commands[] = $text;

            if (preg_match('/ up (--pull always )?--build -d|stack deploy/', $text) === 1) {
                throw new DeploymentGateStarted('started');
            }

            $output = '';
            foreach ($this->outputs as $needle => $value) {
                if (str_contains($text, $needle)) {
                    $output = $value;
                    break;
                }
            }

            if (($save = data_get($command, 'save')) !== null) {
                $saved->put($save, str($output));
            }
        }
    }
}

beforeEach(function () {
    Once::flush();
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Process::fake();
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.max_retries' => 1]);
    InstanceSettings::forceCreate(['id' => 0]);
    $this->withoutDefer();

    $this->team = Team::factory()->create();
    $this->server = deployGateServer($this->team, 'main-server');
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);
    $this->queue = ApplicationDeploymentQueue::create([
        'deployment_uuid' => 'gate-deployment',
        'application_id' => $this->application->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'commit' => GATE_COMMIT,
    ]);
});

function deployGateServer(Team $team, string $name, bool $scanning = true): Server
{
    $server = Server::factory()->create([
        'name' => $name,
        'team_id' => $team->id,
        'private_key_id' => (PrivateKey::query()->where('team_id', $team->id)->first() ?? PrivateKey::factory()->create(['team_id' => $team->id]))->id,
    ]);
    $server->settings->forceFill(['is_reachable' => true, 'is_usable' => true, 'is_image_scan_enabled' => $scanning])->saveQuietly();

    return $server->fresh();
}

function deployGatePolicy(string $onScanError = 'deploy', bool $gate = true): void
{
    ImageScanPolicy::query()->create([
        'scope_type' => test()->application->getMorphClass(),
        'scope_id' => test()->application->id,
        'gate_enabled' => $gate,
        'on_scan_error' => $onScanError,
    ]);
}

/**
 * @return array<string, mixed>
 */
function deployGateFindingRow(string $severity = 'critical'): array
{
    return [
        'id' => 'CVE-2026-1234', 'aliases' => [], 'severity' => $severity, 'package' => 'openssl', 'version' => '3.0.13',
        'type' => 'npm', 'artifactId' => 'a-1', 'fixedIn' => '3.0.15', 'fixState' => 'fixed', 'kev' => false,
        'epss' => null, 'risk' => 1.0, 'summary' => '', 'layer' => 'app',
    ];
}

/**
 * @param  list<array<string, mixed>>  $findings
 */
function deployGateScanAttributes(array $findings): array
{
    return [
        'server_id' => test()->server->id,
        'resource_type' => test()->application->getMorphClass(),
        'resource_id' => test()->application->id,
        'image_id' => GATE_IMAGE_ID,
        'image_ref' => 'app:latest',
        'status' => ImageScanStatus::Completed,
        'builds_image' => true,
        'findings' => $findings,
        'summary' => ['counts' => ['fixable_now' => ['critical' => count($findings)], 'not_released' => [], 'no_fix' => []]],
    ];
}

function deployGateStoredScan(array $findings, array $attributes = []): ImageScan
{
    return ImageScan::factory()->create([...deployGateScanAttributes($findings), ...$attributes]);
}

function deployGateReturnedScan(array $findings): ImageScan
{
    return ImageScan::factory()->make(deployGateScanAttributes($findings));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function deployGateJob(array $overrides = []): DeploymentGateTestJob
{
    $application = test()->application->fresh();
    $imageRef = $overrides['production_image_name'] ?? "{$application->uuid}:".GATE_COMMIT;
    $compose = Yaml::dump(['services' => ['gate-app' => ['image' => $imageRef, 'container_name' => 'gate-app']]], 10);

    $job = new DeploymentGateTestJob;
    $job->outputs = ['docker image inspect' => GATE_IMAGE_ID.' '];
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    foreach ([
        'application' => $application,
        'application_deployment_queue' => test()->queue->fresh(),
        'server' => test()->server,
        'mainServer' => test()->server,
        'build_server' => test()->server,
        'use_build_server' => false,
        'is_this_additional_server' => false,
        'destination' => test()->destination,
        'deployment_uuid' => 'gate-deployment',
        'pull_request_id' => 0,
        'commit' => GATE_COMMIT,
        'rollback' => false,
        'restart_only' => false,
        'basedir' => '/artifacts/gate-deployment',
        'workdir' => '/artifacts/gate-deployment',
        'configuration_dir' => '/data/coolify/applications/gate',
        'container_name' => 'gate-app',
        'production_image_name' => $imageRef,
        'docker_compose' => $compose,
        'generated_docker_compose' => $compose,
        'docker_compose_location' => '/docker-compose.yaml',
        'coolify_variables' => '',
        'preserveRepository' => false,
        'saved_outputs' => new Collection,
        ...$overrides,
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    return $job;
}

function deployGateCall(DeploymentGateTestJob $job, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(ApplicationDeploymentJob::class, $method))->invoke($job, ...$arguments);
}

/**
 * Runs the job until the container start. Returns true when the start was reached.
 */
function deployGateReachesStart(DeploymentGateTestJob $job, string $method = 'rolling_update'): bool
{
    try {
        deployGateCall($job, $method);
    } catch (Throwable $exception) {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof DeploymentGateStarted) {
                return true;
            }
        }

        throw $exception;
    }

    return false;
}

function deployGateBlocked(DeploymentGateTestJob $job, string $method = 'rolling_update'): DeploymentException
{
    try {
        deployGateReachesStart($job, $method);
    } catch (DeploymentException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected the gate to block the deploy.');
}

function deployGateLogs(): string
{
    return collect(json_decode((string) test()->queue->fresh()->logs, true))->pluck('output')->implode("\n");
}

function deployGateStartCommand(DeploymentGateTestJob $job): string
{
    return (string) collect($job->commands)->last();
}

it('starts without a scan when the gate is off and scans after the deploy', function () {
    ScanImage::shouldRun()->never();
    Queue::fake();
    $job = deployGateJob();

    expect(deployGateReachesStart($job))->toBeTrue()
        ->and(collect($job->commands)->contains(fn (string $command) => str_contains($command, 'docker image inspect') || str_contains($command, 'docker pull')))->toBeFalse()
        ->and(test()->queue->fresh()->scan_gate_result)->toBeNull();

    deployGateCall($job, 'post_deployment');

    Queue::assertPushed(ScanResourceImagesJob::class, fn (ScanResourceImagesJob $scanJob) => $scanJob->resource->is(test()->application)
        && $scanJob->deploymentQueueId === test()->queue->id
        && $scanJob->imageRefs === [test()->application->uuid.':'.GATE_COMMIT]
        && $scanJob->queue === 'default');
});

it('does not queue a scan after a skipped build or on an additional server when the gate is off', function (array $overrides) {
    Queue::fake();

    deployGateCall(deployGateJob($overrides), 'post_deployment');

    Queue::assertNotPushed(ScanResourceImagesJob::class);
})->with([
    'skipped build' => [['skip_build' => true]],
    'additional server' => [['is_this_additional_server' => true]],
]);

it('queues a scan after a rollback that skipped the build', function () {
    Queue::fake();

    deployGateCall(deployGateJob(['skip_build' => true, 'rollback' => true]), 'post_deployment');

    Queue::assertPushed(ScanResourceImagesJob::class);
});

it('does not queue a scan after the deploy when scanning is off on the server', function () {
    Queue::fake();
    $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

    deployGateCall(deployGateJob(['server' => $this->server->fresh(), 'mainServer' => $this->server->fresh()]), 'post_deployment');

    Queue::assertNotPushed(ScanResourceImagesJob::class);
});

it('blocks the deploy when the image has a critical vulnerability with a fix', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->once()
        ->withArgs(fn ($server, $resource, $ref, $deployment, $wait) => $server->is(test()->server) && $resource->is(test()->application)
            && $ref === test()->application->uuid.':'.GATE_COMMIT && $deployment->id === test()->queue->id && $wait === 120)
        ->andReturn(deployGateReturnedScan([deployGateFindingRow()]));
    $job = deployGateJob();

    $exception = deployGateBlocked($job);
    $job->failed($exception);

    $queue = test()->queue->fresh();
    expect($exception->getMessage())->toBe('Deploy blocked: 1 critical vulnerability with a fix.')
        ->and($queue->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($queue->scan_gate_result['blocked'])->toBeTrue()
        ->and($queue->scan_gate_result['count'])->toBe(1)
        ->and(deployGateLogs())->toContain('Image scan: 1 critical with a fix.')
        ->toContain('Deploy blocked: 1 critical vulnerability with a fix.')
        ->toContain('CVE-2026-1234  critical  openssl 3.0.13')
        ->and(collect($job->commands)->contains(fn (string $command) => str_contains($command, ' up ')))->toBeFalse();
});

it('starts the deploy when the scan finds nothing to fix now', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->once()->andReturn(deployGateReturnedScan([]));

    expect(deployGateReachesStart(deployGateJob()))->toBeTrue()
        ->and(test()->queue->fresh()->scan_gate_result)->toMatchArray(['blocked' => false, 'scan_ran' => true]);
});

it('reuses the stored scan of an image found on the server and blocks it', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->never();
    deployGateStoredScan([deployGateFindingRow()]);
    $job = deployGateJob(['saved_outputs' => new Collection(['local_image_found' => str('sha256:aaaa')])]);

    expect(deployGateBlocked($job)->getMessage())->toBe('Deploy blocked: 1 critical vulnerability with a fix.')
        ->and(test()->queue->fresh()->scan_gate_result['blocked'])->toBeTrue();
});

it('reuses the scan of the main deployment on an additional server', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->never();
    deployGateStoredScan([deployGateFindingRow()]);
    $additional = deployGateServer($this->team, 'additional-server');
    $job = deployGateJob(['is_this_additional_server' => true, 'server' => $additional, 'mainServer' => $additional]);

    expect(deployGateBlocked($job)->getMessage())->toBe('Deploy blocked: 1 critical vulnerability with a fix.');
});

it('keeps the gate commands intact for a non-root SSH user', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->once()->andReturn(deployGateReturnedScan([]));
    $this->application->update(['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'docker_registry_image_tag' => '1.27']);
    $server = $this->server;
    $server->forceFill(['user' => 'deploy'])->saveQuietly();
    $job = deployGateJob(['production_image_name' => 'nginx:1.27', 'server' => $server->fresh(), 'mainServer' => $server->fresh()]);

    deployGateReachesStart($job);

    $inspect = collect($job->commands)->first(fn (string $command) => str_contains($command, 'docker image inspect'));
    $pull = collect($job->commands)->first(fn (string $command) => str_contains($command, 'docker pull'));
    expect(parseLineForSudo($inspect, $server->fresh()))->toBe("sudo {$inspect}")
        ->and(parseLineForSudo($pull, $server->fresh()))->toStartWith('sudo DOCKER_CONTEXT=default')->toEndWith("docker pull 'nginx:1.27'")
        ->and($pull)->toStartWith('DOCKER_CONTEXT=default DOCKER_CONFIG=');
});

it('blocks a restart that skips the build with the stored scan', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->never();
    deployGateStoredScan([deployGateFindingRow()]);
    ApplicationDeploymentQueue::create([
        'deployment_uuid' => 'gate-previous',
        'application_id' => $this->application->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'commit' => GATE_COMMIT,
        'configuration_snapshot' => $this->application->fresh()->deploymentConfigurationSnapshot(),
    ]);
    $job = deployGateJob([
        'restart_only' => true,
        'saved_outputs' => new Collection(['local_image_found' => str('sha256:aaaa')]),
    ]);

    expect(deployGateBlocked($job, 'should_skip_build')->getMessage())->toBe('Deploy blocked: 1 critical vulnerability with a fix.')
        ->and(collect($job->commands)->contains(fn (string $command) => str_contains($command, ' up ')))->toBeFalse();
});

function deployGatePreviousDeployment(string $uuid, ApplicationDeploymentStatus $status, string $commit = GATE_COMMIT, array $attributes = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'deployment_uuid' => $uuid,
        'application_id' => test()->application->id,
        'server_id' => test()->server->id,
        'status' => $status->value,
        'commit' => $commit,
        ...$attributes,
    ]);
}

it('does not gate a rollback to a commit with a finished deployment', function () {
    deployGatePolicy(onScanError: 'block');
    ScanImage::shouldRun()->never();
    deployGatePreviousDeployment('gate-earlier', ApplicationDeploymentStatus::FINISHED);
    deployGateStoredScan([deployGateFindingRow()]);
    $job = deployGateJob(['rollback' => true]);

    expect(deployGateReachesStart($job))->toBeTrue()
        ->and(test()->queue->fresh()->scan_gate_result)->toBeNull()
        ->and(deployGateLogs())->toContain('Rollback to an image that ran before');
});

it('does not gate a rollback to an image that ran before scanning was turned on', function () {
    deployGatePolicy(onScanError: 'block');
    ScanImage::shouldRun()->never();
    deployGatePreviousDeployment('gate-earlier', ApplicationDeploymentStatus::FINISHED);
    $job = deployGateJob(['rollback' => true]);

    expect(deployGateReachesStart($job))->toBeTrue()
        ->and(ImageScan::query()->count())->toBe(0)
        ->and(test()->queue->fresh()->scan_gate_result)->toBeNull();
});

it('gates a rollback to the commit of a blocked deployment', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->never();
    // The blocked image stays tagged on the server, so it is in the rollback list. That alone does not count.
    GetRollbackImages::shouldNotRun();
    $blocked = deployGatePreviousDeployment('gate-blocked', ApplicationDeploymentStatus::FAILED, attributes: [
        'scan_gate_result' => ['blocked' => true, 'scan_ran' => true, 'error' => null, 'count' => 1, 'headline' => 'Deploy blocked: 1 critical vulnerability with a fix.', 'top' => []],
    ]);
    deployGateStoredScan([deployGateFindingRow()], ['deployment_queue_id' => $blocked->id]);
    $job = deployGateJob(['rollback' => true]);

    expect(deployGateBlocked($job)->getMessage())->toBe('Deploy blocked: 1 critical vulnerability with a fix.');
});

it('gates a rollback to a commit that a finished deployment of another application ran', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->once()->andReturn(deployGateReturnedScan([deployGateFindingRow()]));
    $other = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    ApplicationDeploymentQueue::create([
        'deployment_uuid' => 'gate-other-app',
        'application_id' => $other->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'commit' => GATE_COMMIT,
    ]);

    expect(deployGateBlocked(deployGateJob(['rollback' => true]))->getMessage())->toBe('Deploy blocked: 1 critical vulnerability with a fix.');
});

it('does not gate a rollback to an image that a finished deployment scanned', function () {
    deployGatePolicy(onScanError: 'block');
    ScanImage::shouldRun()->never();
    $finished = deployGatePreviousDeployment('gate-finished', ApplicationDeploymentStatus::FINISHED, commit: 'HEAD');
    deployGateStoredScan([deployGateFindingRow()], ['deployment_queue_id' => $finished->id]);
    $job = deployGateJob(['rollback' => true]);

    expect(deployGateReachesStart($job))->toBeTrue()
        ->and(test()->queue->fresh()->scan_gate_result)->toBeNull();
});

it('gates a rollback to an image that never ran', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->once()->andReturn(deployGateReturnedScan([deployGateFindingRow()]));
    $job = deployGateJob(['rollback' => true]);

    expect(deployGateBlocked($job)->getMessage())->toBe('Deploy blocked: 1 critical vulnerability with a fix.');
});

it('starts a deploy that a user deployed anyway and records it', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->never();
    $user = User::factory()->create(['name' => 'Ada Admin']);
    test()->queue->update(['scan_gate_bypassed_by' => $user->id]);

    expect(deployGateReachesStart(deployGateJob()))->toBeTrue()
        ->and(deployGateLogs())->toContain('Deploy anyway by Ada Admin.');

    $event = AuditEvent::query()->where('event', 'ui.application.image_scan_gate_bypassed')->sole();
    expect($event->team_id)->toBe($this->team->id)
        ->and($event->resource_uuid)->toBe($this->application->uuid)
        ->and($event->actor_id)->toBe($user->id)
        ->and($event->actor_name)->toBe('Ada Admin')
        ->and(data_get($event->metadata, 'deployment_uuid'))->toBe('gate-deployment')
        ->and(data_get($event->metadata, 'user_id'))->toBe($user->id);
});

it('starts the deployment of an additional server when the main deployment was deployed anyway', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->never();
    deployGateStoredScan([deployGateFindingRow()]);
    $user = User::factory()->create(['name' => 'Ada Admin']);
    ApplicationDeploymentQueue::create([
        'deployment_uuid' => 'gate-parent',
        'application_id' => $this->application->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'scan_gate_bypassed_by' => $user->id,
    ]);
    test()->queue->update(['parent_deployment_uuid' => 'gate-parent']);

    expect(deployGateReachesStart(deployGateJob(['is_this_additional_server' => true])))->toBeTrue()
        ->and(deployGateLogs())->toContain('Deploy anyway by Ada Admin.');
});

it('deploys with a warning when scanning is off on the server and the rule says deploy', function () {
    deployGatePolicy(onScanError: 'deploy');
    ScanImage::shouldRun()->never();
    $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

    expect(deployGateReachesStart(deployGateJob(['server' => $this->server->fresh(), 'mainServer' => $this->server->fresh()])))->toBeTrue()
        ->and(deployGateLogs())->toContain('Scan skipped: Turn on image scanning on main-server.')
        ->and(test()->queue->fresh()->scan_gate_result)->toMatchArray(['blocked' => false, 'error' => ImageScanErrorCode::ScanningOff->value]);
});

it('blocks the deploy when scanning is off on the server and the rule says block', function () {
    deployGatePolicy(onScanError: 'block');
    ScanImage::shouldRun()->never();
    $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

    $exception = deployGateBlocked(deployGateJob(['server' => $this->server->fresh(), 'mainServer' => $this->server->fresh()]));

    expect($exception->getMessage())->toBe('Deploy blocked: Turn on image scanning on main-server.')
        ->and(test()->queue->fresh()->scan_gate_result['blocked'])->toBeTrue();
});

it('pulls a Docker image, pins its digest, and starts it without a compose pull', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->once()
        ->withArgs(fn ($server, $resource, $ref) => $ref === 'nginx:1.27')
        ->andReturn(deployGateReturnedScan([]));
    $this->application->update(['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'docker_registry_image_tag' => '1.27']);
    $job = deployGateJob(['production_image_name' => 'nginx:1.27']);
    $job->outputs = ['docker image inspect' => GATE_IMAGE_ID.' nginx@'.GATE_REPO_DIGEST.' '];

    expect(deployGateReachesStart($job))->toBeTrue();

    $compose = (new ReflectionProperty(ApplicationDeploymentJob::class, 'docker_compose'))->getValue($job);
    expect(Yaml::parse($compose)['services']['gate-app']['image'])->toBe('nginx@'.GATE_REPO_DIGEST)
        ->and($compose)->toContain('nginx@sha256:')
        ->and(collect($job->commands)->first(fn (string $command) => str_contains($command, 'docker pull')))->toContain("docker pull 'nginx:1.27'")
        ->and(collect($job->commands)->contains(fn (string $command) => preg_match('/docker compose .* pull/', $command) === 1))->toBeFalse();
});

it('keeps the compose pull of a Docker image when the gate is off', function () {
    ScanImage::shouldRun()->never();
    $this->application->update(['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'docker_registry_image_tag' => '1.27']);
    $job = deployGateJob(['production_image_name' => 'nginx:1.27']);

    expect(deployGateReachesStart($job))->toBeTrue()
        ->and(collect($job->commands)->contains(fn (string $command) => preg_match('/docker compose .* pull/', $command) === 1))->toBeTrue()
        ->and(Yaml::parse((new ReflectionProperty(ApplicationDeploymentJob::class, 'docker_compose'))->getValue($job))['services']['gate-app']['image'])->toBe('nginx:1.27');
});

it('gates a built image without a repo digest by its local image ID and does not pin it', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->never();
    deployGateStoredScan([deployGateFindingRow()]);
    $buildServer = deployGateServer($this->team, 'build-server');
    $buildServer->settings->forceFill(['is_build_server' => true])->saveQuietly();
    $job = deployGateJob(['use_build_server' => true, 'build_server' => $buildServer, 'server' => $buildServer]);
    $job->outputs = ['docker image inspect' => GATE_IMAGE_ID.' '];

    expect(deployGateBlocked($job)->getMessage())->toBe('Deploy blocked: 1 critical vulnerability with a fix.')
        ->and(Yaml::parse((new ReflectionProperty(ApplicationDeploymentJob::class, 'docker_compose'))->getValue($job))['services']['gate-app']['image'])
        ->toBe($this->application->uuid.':'.GATE_COMMIT);
});

it('pins the pushed digest of a build server image and starts it without --pull always', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->once()
        ->withArgs(fn ($server) => $server->name === 'build-server')
        ->andReturn(deployGateReturnedScan([]));
    $this->application->update(['docker_registry_image_name' => 'ghcr.io/team/app']);
    $buildServer = deployGateServer($this->team, 'build-server');
    $imageRef = 'ghcr.io/team/app:'.GATE_COMMIT;
    $job = deployGateJob(['use_build_server' => true, 'build_server' => $buildServer, 'server' => $buildServer, 'production_image_name' => $imageRef]);
    $job->outputs = ['docker image inspect' => GATE_IMAGE_ID.' ghcr.io/team/app@'.GATE_REPO_DIGEST.' '];

    expect(deployGateReachesStart($job))->toBeTrue();

    $compose = (new ReflectionProperty(ApplicationDeploymentJob::class, 'generated_docker_compose'))->getValue($job);
    expect(Yaml::parse($compose)['services']['gate-app']['image'])->toBe('ghcr.io/team/app@'.GATE_REPO_DIGEST)
        ->and(deployGateStartCommand($job))->toContain(' up --build -d')->not->toContain('--pull always');
});

it('resolves the rules with the build server and reports when scanning is off there', function () {
    deployGatePolicy(onScanError: 'block');
    ScanImage::shouldRun()->never();
    $buildServer = deployGateServer($this->team, 'build-server', scanning: false);
    $job = deployGateJob(['use_build_server' => true, 'build_server' => $buildServer, 'server' => $buildServer]);

    expect(deployGateBlocked($job)->getMessage())->toBe('Deploy blocked: Image scanning is off on the build server.')
        ->and(test()->queue->fresh()->scan_gate_result['error'])->toBe(ImageScanErrorCode::BuildServerOff->value);
});

it('scans a restart on the deployment server with its rules when the application has a build server', function () {
    deployGatePolicy(onScanError: 'block');
    $buildServer = deployGateServer($this->team, 'build-server', scanning: false);
    ScanImage::shouldRun()->once()
        ->withArgs(fn ($server) => $server->is(test()->server))
        ->andReturn(deployGateReturnedScan([]));
    $job = deployGateJob(['use_build_server' => true, 'build_server' => $buildServer, 'restart_only' => true]);

    expect(deployGateReachesStart($job))->toBeTrue()
        ->and(test()->queue->fresh()->scan_gate_result)->toMatchArray(['blocked' => false, 'error' => null, 'scan_ran' => true]);
});

it('blocks a Compose deploy with a custom start command when the rule says block', function () {
    deployGatePolicy(onScanError: 'block');
    ScanImage::shouldRun()->never();
    $this->application->update(['build_pack' => 'dockercompose']);
    $job = deployGateJob(['docker_compose_custom_start_command' => 'docker compose up -d']);

    try {
        deployGateCall($job, 'checkComposeImageScanGate');
        $this->fail('Expected the gate to block the deploy.');
    } catch (DeploymentException $exception) {
        expect($exception->getMessage())->toBe('Deploy blocked: Coolify cannot check the image because this application uses a custom build or start command.');
    }
});

it('reports the custom command for a raw Compose deploy whose services have no image', function () {
    deployGatePolicy(onScanError: 'block');
    ScanImage::shouldRun()->never();
    $this->application->update(['build_pack' => 'dockercompose']);
    $job = deployGateJob([
        'docker_compose_custom_build_command' => 'docker compose build',
        'generated_docker_compose' => Yaml::dump(['services' => ['web' => ['build' => '.']]], 10),
    ]);

    try {
        deployGateCall($job, 'checkComposeImageScanGate');
        $this->fail('Expected the gate to block the deploy.');
    } catch (DeploymentException $exception) {
        expect($exception->getMessage())->toBe('Deploy blocked: Coolify cannot check the image because this application uses a custom build or start command.')
            ->and(collect($job->commands)->contains(fn (string $command) => str_contains($command, 'docker image inspect')))->toBeFalse();
    }
});

it('keeps the worst gate result of a Compose deploy', function () {
    deployGatePolicy(onScanError: 'deploy');
    $this->application->update(['build_pack' => 'dockercompose']);
    ScanImage::shouldRun()->twice()->andReturn(
        (new ImageScan)->forceFill(['status' => ImageScanStatus::Failed, 'error_code' => ImageScanErrorCode::Timeout, 'error_context' => []]),
        deployGateReturnedScan([]),
    );
    $job = deployGateJob(['generated_docker_compose' => Yaml::dump(['services' => [
        'web' => ['image' => 'nginx:1.27'],
        'cache' => ['image' => 'redis:7'],
    ]], 10)]);
    $job->outputs = ['docker image inspect' => ''];

    deployGateCall($job, 'checkComposeImageScanGate');

    expect(test()->queue->fresh()->scan_gate_result)->toMatchArray(['blocked' => false, 'error' => ImageScanErrorCode::Timeout->value]);
});

it('scans each Compose image and stops Compose from pulling the scanned images', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->twice()->andReturn(deployGateReturnedScan([]));
    $this->application->update(['build_pack' => 'dockercompose']);
    $compose = Yaml::dump(['services' => [
        'web' => ['image' => 'nginx:1.27'],
        'worker' => ['image' => $this->application->uuid.'_worker:'.GATE_COMMIT, 'build' => ['context' => '.']],
    ]], 10);
    $job = deployGateJob(['generated_docker_compose' => $compose]);

    deployGateCall($job, 'checkComposeImageScanGate');

    $services = Yaml::parse((new ReflectionProperty(ApplicationDeploymentJob::class, 'generated_docker_compose'))->getValue($job))['services'];
    expect($services['web']['pull_policy'])->toBe('never')
        ->and($services['worker']['pull_policy'])->toBe('never')
        ->and(collect($job->commands)->contains(fn (string $command) => str_contains($command, 'tee /artifacts/gate-deployment/docker-compose.yaml')))->toBeTrue();
});

it('scans the started images one at a time on the deployment server in the queued job', function () {
    $scanned = [];
    ScanImage::shouldRun()->twice()
        ->withArgs(function ($server, $resource, $ref, $deployment, $wait) use (&$scanned) {
            $scanned[] = $ref;

            return $server->is(test()->server) && $resource->is(test()->application) && $deployment->id === test()->queue->id && $wait === 0;
        })
        ->andReturn(deployGateReturnedScan([]));

    $job = new ScanResourceImagesJob($this->application, $this->queue->id, ['nginx:1.27', 'redis:7']);
    $job->handle();

    expect($scanned)->toBe(['nginx:1.27', 'redis:7'])
        ->and($job->timeout)->toBe(2 * ScanImage::LOCK_TTL);
});

it('scans the images of the latest completed scans when no image is given', function () {
    deployGateStoredScan([], ['image_ref' => 'old:1', 'image_id' => 'sha256:'.str_repeat('c', 64), 'scanned_at' => now()->subDay()]);
    deployGateStoredScan([], ['image_ref' => 'new:2', 'scanned_at' => now()]);
    deployGateStoredScan([], ['image_ref' => 'failed:3', 'image_id' => 'sha256:'.str_repeat('d', 64), 'status' => ImageScanStatus::Failed]);
    $scanned = [];
    ScanImage::shouldRun()->twice()
        ->withArgs(function ($server, $resource, $ref) use (&$scanned) {
            $scanned[] = $ref;

            return true;
        })
        ->andReturn(deployGateReturnedScan([]));

    (new ScanResourceImagesJob($this->application))->handle();

    expect($scanned)->toBe(['new:2', 'old:1']);
});

/**
 * A job after the build of a Dockerfile app: the Dockerfile was read from the repository.
 */
function deployGateDockerfileJob(string $dockerfile = "FROM node:20.11-alpine AS build\nRUN npm ci\nFROM build\n", array $overrides = []): DeploymentGateTestJob
{
    return deployGateJob(['saved_outputs' => new Collection(['dockerfile_from_repo' => str($dockerfile)]), ...$overrides]);
}

function deployGateBaseImageRef(DeploymentGateTestJob $job): ?string
{
    return (new ReflectionProperty(ApplicationDeploymentJob::class, 'imageScanBaseImageRef'))->getValue($job);
}

it('passes the base image of a Dockerfile build to the gate scan without a remote command', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->once()
        ->withArgs(fn ($server, $resource, $ref, $deployment, $wait, $baseImageRef) => $baseImageRef === 'node:20.11-alpine')
        ->andReturn(deployGateReturnedScan([]));
    $job = deployGateDockerfileJob();

    deployGateCall($job, 'rememberDockerfileBaseImage');

    expect($job->commands)->toBe([])
        ->and(deployGateReachesStart($job))->toBeTrue();
});

it('passes the base image of a Dockerfile build to the scan after the deploy', function () {
    Queue::fake();
    $job = deployGateDockerfileJob();

    deployGateCall($job, 'rememberDockerfileBaseImage');
    deployGateCall($job, 'post_deployment');

    Queue::assertPushed(ScanResourceImagesJob::class, fn (ScanResourceImagesJob $scanJob) => $scanJob->baseImageRef === 'node:20.11-alpine');
});

it('scans the started image with the base image in the queued job', function () {
    ScanImage::shouldRun()->once()
        ->withArgs(fn ($server, $resource, $ref, $deployment, $wait, $baseImageRef) => $ref === 'app:1' && $baseImageRef === 'node:20.11-alpine')
        ->andReturn(deployGateReturnedScan([]));

    (new ScanResourceImagesJob($this->application, $this->queue->id, ['app:1'], false, 'node:20.11-alpine'))->handle();
});

it('reads the base image of an inline Dockerfile and of the build target', function (array $application, string $expected) {
    $this->application->update($application);
    $job = deployGateDockerfileJob("FROM alpine:3.20\n");

    deployGateCall($job, 'rememberDockerfileBaseImage');

    expect(deployGateBaseImageRef($job))->toBe($expected);
})->with([
    'inline Dockerfile' => [['dockerfile' => "FROM nginx:1.27-alpine\nCOPY . /usr/share/nginx/html\n"], 'nginx:1.27-alpine'],
    'build target' => [['dockerfile' => "FROM node:20 AS dev\nFROM nginx:1.27 AS prod\n", 'dockerfile_target_build' => 'dev'], 'node:20'],
]);

it('reads no base image when it cannot be known or scanning is off', function (string $dockerfile, string $buildPack, bool $scanning) {
    $this->application->update(['build_pack' => $buildPack]);
    $this->server->settings->forceFill(['is_image_scan_enabled' => $scanning])->saveQuietly();
    $job = deployGateDockerfileJob($dockerfile, ['server' => $this->server->fresh(), 'mainServer' => $this->server->fresh()]);

    deployGateCall($job, 'rememberDockerfileBaseImage');

    expect(deployGateBaseImageRef($job))->toBeNull();
})->with([
    'ARG in FROM' => ["ARG BASE=node:20\nFROM \${BASE}\n", 'dockerfile', true],
    'scratch' => ["FROM golang:1.23 AS build\nFROM scratch\n", 'dockerfile', true],
    'nixpacks' => ["FROM node:20\n", 'nixpacks', true],
    'scanning off on the server' => ["FROM node:20\n", 'dockerfile', false],
]);

it('does not fail the deploy when the Dockerfile cannot be read', function () {
    $job = deployGateJob(['saved_outputs' => new Collection]);

    deployGateCall($job, 'rememberDockerfileBaseImage');

    expect(deployGateBaseImageRef($job))->toBeNull();
});

it('gates a build server image with the rules of the main destination server', function () {
    ImageScanPolicy::query()->create(['scope_type' => $this->server->getMorphClass(), 'scope_id' => $this->server->id, 'gate_enabled' => true, 'alert_level' => 'high']);
    $buildServer = deployGateServer($this->team, 'build-server');
    ImageScanPolicy::query()->create(['scope_type' => $buildServer->getMorphClass(), 'scope_id' => $buildServer->id, 'gate_enabled' => false, 'alert_level' => 'critical']);
    ScanImage::shouldRun()->once()
        ->withArgs(fn ($server) => $server->is($buildServer))
        ->andReturn(deployGateReturnedScan([deployGateFindingRow('high')]));
    $job = deployGateJob(['use_build_server' => true, 'build_server' => $buildServer, 'server' => $buildServer]);

    expect(deployGateBlocked($job)->getMessage())->toBe('Deploy blocked: 1 high vulnerability with a fix.');
});

/**
 * A stored scan of nginx:1.27, an image that the team does not build, with a critical package fix.
 */
function deployGateOfficialImageScan(?array $candidates = null): ImageScan
{
    return deployGateStoredScan([deployGateFindingRow()], [
        'image_ref' => 'nginx:1.27',
        'builds_image' => false,
        'candidates' => $candidates,
        'deployment_queue_id' => test()->queue->id,
    ]);
}

function deployGateOfficialImageJob(): DeploymentGateTestJob
{
    test()->application->update(['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'docker_registry_image_tag' => '1.27']);
    $job = deployGateJob(['production_image_name' => 'nginx:1.27']);
    $job->outputs = ['docker image inspect' => GATE_IMAGE_ID.' nginx@'.GATE_REPO_DIGEST.' '];

    return $job;
}

/**
 * @param  array<string, mixed>  $candidates
 */
function deployGateStoreCandidates(ImageScan $scan, array $candidates): void
{
    $row = ImageScan::query()->findOrFail($scan->id);
    $row->candidates = ['checked_at' => now()->toIso8601String(), 'error' => null, 'tags_truncated' => false, 'entries' => [], ...$candidates];
    $row->save();
}

it('checks newer versions before the gate on the first deploy of an official image and blocks a fix', function () {
    deployGatePolicy();
    $job = deployGateOfficialImageJob();
    ScanImage::shouldRun()->once()
        ->withArgs(fn ($server, $resource, $ref, $deployment, $wait, $baseImageRef, $queueCandidateCheck) => $queueCandidateCheck === false)
        ->andReturnUsing(fn () => deployGateOfficialImageScan());
    RefreshCandidates::shouldRun()->once()
        ->withArgs(fn ($checked, $wait) => $checked->image_ref === 'nginx:1.27' && $wait === ScanImage::GATE_LOCK_WAIT_SECONDS)
        ->andReturnUsing(fn ($checked) => deployGateStoreCandidates($checked, ['status' => 'ok', 'entries' => [
            ['ref' => 'nginx:1.28', 'digest' => GATE_REPO_DIGEST, 'kind' => 'line', 'better' => true, 'fixed_ids' => ['CVE-2026-1234']],
        ]]));

    expect(deployGateBlocked($job)->getMessage())->toBe('Deploy blocked: 1 critical vulnerability with a fix.')
        ->and(test()->queue->fresh()->scan_gate_result['repo_digest'])->toBe('nginx@'.GATE_REPO_DIGEST)
        ->and(deployGateLogs())->not->toContain('Coolify could not check newer versions');
});

it('deploys an official image when the check of newer versions fails and says why', function () {
    deployGatePolicy();
    $job = deployGateOfficialImageJob();
    ScanImage::shouldRun()->once()->andReturnUsing(fn () => deployGateOfficialImageScan());
    RefreshCandidates::shouldRun()->once()
        ->andReturnUsing(fn ($checked) => deployGateStoreCandidates($checked, ['status' => 'failed', 'error' => 'daily_limit']));

    expect(deployGateReachesStart($job))->toBeTrue()
        ->and(test()->queue->fresh()->scan_gate_result)->toMatchArray(['blocked' => false, 'scan_ran' => true])
        ->and(deployGateLogs())->toContain('Coolify could not check newer versions of nginx:1.27: the daily limit of version checks on main-server is reached.');
});

it('does not check newer versions again before the gate within 20 hours', function () {
    deployGatePolicy();
    Queue::fake();
    $job = deployGateOfficialImageJob();
    deployGateOfficialImageScan(['status' => 'failed', 'error' => 'registry', 'checked_at' => now()->subHours(2)->toIso8601String(), 'entries' => []]);
    ScanImage::shouldRun()->never();
    RefreshCandidates::shouldRun()->never();

    expect(deployGateReachesStart($job))->toBeTrue();
});

it('queues the check of newer versions for an image the team builds', function () {
    deployGatePolicy();
    Queue::fake();
    $job = deployGateJob();
    ScanImage::shouldRun()->once()->andReturnUsing(fn () => deployGateStoredScan([deployGateFindingRow()], ['base_image_ref' => 'node:20.11-alpine', 'deployment_queue_id' => test()->queue->id]));
    RefreshCandidates::shouldRun()->never();

    deployGateBlocked($job);

    Queue::assertPushed(RefreshCandidatesJob::class, fn (RefreshCandidatesJob $refresh) => $refresh->scan->base_image_ref === 'node:20.11-alpine');
});

it('links a reused scan to the deployment that passed the gate so unchanged images stay current', function () {
    deployGatePolicy();
    $this->application->update(['build_pack' => 'dockercompose']);
    $previous = deployGatePreviousDeployment('gate-previous', ApplicationDeploymentStatus::FINISHED);
    $idOf = fn (string $char) => 'sha256:'.str_repeat($char, 64);
    deployGateStoredScan([], ['image_ref' => 'web:1', 'image_id' => $idOf('1'), 'deployment_queue_id' => $previous->id, 'scanned_at' => now()->subHour()]);
    deployGateStoredScan([], ['image_ref' => 'cache:1', 'image_id' => $idOf('2'), 'deployment_queue_id' => $previous->id, 'scanned_at' => now()->subHour()]);
    deployGateStoredScan([], ['image_ref' => 'worker:1', 'image_id' => $idOf('3'), 'deployment_queue_id' => $previous->id, 'scanned_at' => now()->subHour()]);
    ScanImage::shouldRun()->once()
        ->withArgs(fn ($server, $resource, $ref) => $ref === 'web:2')
        ->andReturnUsing(fn () => deployGateStoredScan([], ['image_ref' => 'web:2', 'image_id' => $idOf('4'), 'deployment_queue_id' => test()->queue->id, 'scanned_at' => now()]));
    $job = deployGateJob(['generated_docker_compose' => Yaml::dump(['services' => [
        'web' => ['image' => 'web:2'],
        'cache' => ['image' => 'cache:1'],
        'worker' => ['image' => 'worker:1'],
    ]], 10)]);
    $job->outputs = ["'web:2'" => $idOf('4').' ', "'cache:1'" => $idOf('2').' ', "'worker:1'" => $idOf('3').' '];

    deployGateCall($job, 'checkComposeImageScanGate');

    expect(ResourceScans::currentFor($this->application->fresh())->pluck('image_ref')->sort()->values()->all())
        ->toBe(['cache:1', 'web:2', 'worker:1']);
});

it('keeps the link of a reused scan for a preview deployment', function () {
    deployGatePolicy();
    $previous = deployGatePreviousDeployment('gate-previous', ApplicationDeploymentStatus::FINISHED);
    $scan = deployGateStoredScan([], ['deployment_queue_id' => $previous->id]);
    ScanImage::shouldRun()->never();
    test()->queue->update(['pull_request_id' => 3]);

    expect(deployGateReachesStart(deployGateJob(['pull_request_id' => 3])))->toBeTrue()
        ->and($scan->fresh()->deployment_queue_id)->toBe($previous->id);
});

it('scans a Compose image after the start when its gate scan could not run', function () {
    deployGatePolicy(onScanError: 'deploy');
    Queue::fake();
    $this->application->update(['build_pack' => 'dockercompose']);
    ScanImage::shouldRun()->twice()->andReturn(
        deployGateReturnedScan([]),
        (new ImageScan)->forceFill(['status' => ImageScanStatus::Failed, 'error_code' => ImageScanErrorCode::Timeout, 'error_context' => []]),
    );
    $job = deployGateJob(['generated_docker_compose' => Yaml::dump(['services' => [
        'web' => ['image' => 'nginx:1.27'],
        'cache' => ['image' => 'redis:7'],
    ]], 10)]);

    deployGateCall($job, 'checkComposeImageScanGate');
    deployGateCall($job, 'post_deployment');

    Queue::assertPushed(ScanResourceImagesJob::class, fn (ScanResourceImagesJob $scanJob) => $scanJob->imageRefs === ['redis:7']);
});

it('starts the blocked image by its digest when a user deployed a Docker image anyway', function () {
    deployGatePolicy();
    ScanImage::shouldRun()->never();
    $this->application->update(['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'docker_registry_image_tag' => 'latest']);
    $user = User::factory()->create(['name' => 'Ada Admin']);
    test()->queue->update(['scan_gate_bypassed_by' => $user->id, 'scan_gate_result' => ['repo_digest' => 'nginx@'.GATE_REPO_DIGEST]]);
    $job = deployGateJob(['production_image_name' => 'nginx:latest']);

    expect(deployGateReachesStart($job))->toBeTrue();

    $compose = (new ReflectionProperty(ApplicationDeploymentJob::class, 'docker_compose'))->getValue($job);
    expect(Yaml::parse($compose)['services']['gate-app']['image'])->toBe('nginx@'.GATE_REPO_DIGEST)
        ->and(collect($job->commands)->contains(fn (string $command) => str_contains($command, 'docker pull') || preg_match('/docker compose .* pull/', $command) === 1))->toBeFalse()
        ->and(deployGateLogs())->toContain('Deploy anyway by Ada Admin.');
});
