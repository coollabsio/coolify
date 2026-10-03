<?php

use App\Actions\Service\DeployServiceApplication;
use App\Actions\Service\StartService;
use App\Actions\Shared\EnsureContentFilesOnServer;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\CoolifyTask;
use App\Jobs\ServerStorageSaveJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Support\RemoteProcessCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const CONTENT_FILES_UP_CONTENT = 'listen 8080;';

const CONTENT_FILES_UP_SERVICE_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - type: bind
        source: ./config/app.conf
        target: /etc/app.conf
        content: 'listen 8080;'
  worker:
    image: nginx:alpine
    volumes:
      - type: bind
        source: ./config/worker.conf
        target: /etc/worker.conf
        content: 'workers 4;'
YAML;

/**
 * Every server command and every deployment command, in the order they ran.
 */
final class ContentFilesUpRecorder
{
    /** @var list<array{string, string}> */
    public static array $commands = [];

    public static function indexOf(string $channel, string $needle): ?int
    {
        foreach (self::$commands as $index => [$recordedChannel, $command]) {
            if ($recordedChannel === $channel && str_contains($command, $needle)) {
                return $index;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function ssh(): array
    {
        return collect(self::$commands)->where(0, 'ssh')->pluck(1)->values()->all();
    }
}

/**
 * Records the deployment commands instead of running them.
 */
class ContentFilesUpDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}

    public function execute_remote_command(...$commands): void
    {
        foreach ($commands as $command) {
            ContentFilesUpRecorder::$commands[] = ['deployment', $command['command'] ?? $command[0]];
        }
    }
}

/**
 * Fakes the server file system. $states maps a path to `file`, `missing`, `empty-directory`,
 * `directory` or `other`. The symlink check always passes.
 *
 * @param  array<string, string>  $states
 */
function fakeContentFilesServer(array $states): void
{
    ContentFilesUpRecorder::$commands = [];

    Process::fake(function ($process) use ($states) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        ContentFilesUpRecorder::$commands[] = ['ssh', $command];

        if (str_contains($command, 'empty-directory')) {
            $positions = collect($states)
                ->map(fn (string $state, string $path) => strpos($command, escapeshellarg($path)))
                ->filter(fn ($position) => $position !== false)
                ->sort();
            $lines = $positions->keys()->values()->map(fn (string $path, int $index) => ($index + 1).':'.$states[$path]);

            return Process::result(output: $lines->implode("\n"));
        }
        if (str_contains($command, 'readlink -f')) {
            return Process::result(output: 'OK');
        }
        foreach ($states as $path => $state) {
            if (str_contains($command, 'test -f '.escapeshellarg($path))) {
                return Process::result(output: $state === 'file' ? 'OK' : 'NOK');
            }
            if (str_contains($command, 'test -d '.escapeshellarg($path))) {
                return Process::result(output: in_array($state, ['empty-directory', 'directory'], true) ? 'OK' : 'NOK');
            }
        }

        return Process::result(output: str_contains($command, 'test -') ? 'NOK' : '');
    });
}

function contentFilesStateChecks(): array
{
    return array_values(array_filter(ContentFilesUpRecorder::ssh(), fn (string $command) => str_contains($command, 'empty-directory')));
}

function contentFilesAssertNoWrite(): void
{
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'base64 -d'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'rmdir'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'rm -fr'));
}

beforeEach(function () {
    Server::flushIdentityMap();
    Bus::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    config([
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_location' => '/docker-compose.yaml',
    ]);
    $this->logEntries = [];
});

/**
 * Bus::fake() keeps the ServerStorageSaveJob of the `created` hook from running.
 *
 * @param  array<string, mixed>  $attributes
 */
function contentFilesVolume(Application|Service $resource, string $relativePath, array $attributes = []): LocalFileVolume
{
    $owner = $resource instanceof Service ? $resource->applications()->firstOrFail() : $resource;

    return LocalFileVolume::create([
        'fs_path' => $resource->workdir().'/'.$relativePath,
        'mount_path' => '/etc/'.basename($relativePath),
        'content' => CONTENT_FILES_UP_CONTENT,
        'is_directory' => false,
        'resource_id' => $owner->id,
        'resource_type' => $owner->getMorphClass(),
        ...$attributes,
    ])->fresh();
}

/**
 * Runs the part of a Compose deployment that starts the containers.
 *
 * @param  array<string, mixed>  $properties
 */
function runComposeStart(object $test, array $properties = []): ContentFilesUpDeploymentJob
{
    $job = new ContentFilesUpDeploymentJob;
    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message, string $type = 'stdout') use ($test) {
        $test->logEntries[] = [$message, $type];
    });

    foreach ([
        'application' => $test->application->fresh(),
        'application_deployment_queue' => $queue,
        'server' => $test->server->fresh(),
        'mainServer' => $test->server->fresh(),
        'deployment_uuid' => 'deployment-uuid',
        'workdir' => '/artifacts/deployment-uuid',
        'configuration_dir' => $test->application->workdir(),
        'docker_compose_location' => '/docker-compose.yaml',
        'coolify_variables' => '',
        'pull_request_id' => 0,
        'preserveRepository' => false,
        'saved_outputs' => collect(),
        ...$properties,
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    (new ReflectionMethod(ApplicationDeploymentJob::class, 'start_docker_compose_services'))->invoke($job);

    return $job;
}

function contentFilesLogText(object $test): string
{
    return collect($test->logEntries)->pluck(0)->implode("\n");
}

test('a Compose deployment writes a missing content file before docker compose up', function () {
    $volume = contentFilesVolume($this->application, 'config/app.conf');
    fakeContentFilesServer([$volume->fs_path => 'missing']);

    runComposeStart($this);

    $write = ContentFilesUpRecorder::indexOf('ssh', 'base64 -d | tee '.escapeshellarg($volume->fs_path));
    $up = ContentFilesUpRecorder::indexOf('deployment', ' up -d');
    expect($write)->not->toBeNull()
        ->and($up)->not->toBeNull()
        ->and($write)->toBeLessThan($up)
        ->and(ContentFilesUpRecorder::ssh()[$write])->toContain(base64_encode(CONTENT_FILES_UP_CONTENT))
        ->and($this->logEntries)->toContain(['Writing 1 missing configuration file.', 'stdout'])
        ->and(contentFilesLogText($this))->not->toContain(CONTENT_FILES_UP_CONTENT)
        ->and(contentFilesLogText($this))->not->toContain(base64_encode(CONTENT_FILES_UP_CONTENT));
});

test('a Compose deployment checks all content files with one server command', function () {
    $first = contentFilesVolume($this->application, 'config/app.conf');
    $second = contentFilesVolume($this->application, 'config/worker.conf');
    $third = contentFilesVolume($this->application, 'nginx.conf');
    fakeContentFilesServer([$first->fs_path => 'file', $second->fs_path => 'missing', $third->fs_path => 'file']);

    runComposeStart($this);

    expect(contentFilesStateChecks())->toHaveCount(1)
        ->and(contentFilesStateChecks()[0])->toContain(escapeshellarg($first->fs_path))
        ->toContain(escapeshellarg($second->fs_path))
        ->toContain(escapeshellarg($third->fs_path))
        ->and($this->logEntries)->toContain(['Writing 1 missing configuration file.', 'stdout']);
    Process::assertRan(fn ($process) => str_contains($process->command, 'tee '.escapeshellarg($second->fs_path)));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'tee '.escapeshellarg($first->fs_path)));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'tee '.escapeshellarg($third->fs_path)));
});

test('a Compose deployment does not change a content file that exists on the server', function () {
    $volume = contentFilesVolume($this->application, 'config/app.conf');
    fakeContentFilesServer([$volume->fs_path => 'file']);

    runComposeStart($this);

    contentFilesAssertNoWrite();
    expect(contentFilesStateChecks())->toHaveCount(1)
        ->and(contentFilesLogText($this))->not->toContain('Writing')
        ->and(ContentFilesUpRecorder::indexOf('deployment', ' up -d'))->not->toBeNull();
});

test('a Compose deployment replaces an empty directory with the content file', function () {
    $volume = contentFilesVolume($this->application, 'config/app.conf');
    fakeContentFilesServer([$volume->fs_path => 'empty-directory']);

    runComposeStart($this);

    $escapedPath = escapeshellarg($volume->fs_path);
    $write = ContentFilesUpRecorder::indexOf('ssh', "base64 -d | tee {$escapedPath}");
    $writeCommand = ContentFilesUpRecorder::$commands[$write][1];
    expect($write)->not->toBeNull()
        ->and($write)->toBeLessThan(ContentFilesUpRecorder::indexOf('deployment', ' up -d'))
        ->and(strpos($writeCommand, "rmdir {$escapedPath}"))->toBeLessThan(strpos($writeCommand, "tee {$escapedPath}"))
        ->and($this->logEntries)->toContain(['Writing 1 missing configuration file.', 'stdout']);
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'rm -fr'));
});

test('a Compose deployment keeps a directory that is not empty and shows a warning', function () {
    $volume = contentFilesVolume($this->application, 'config/app.conf');
    fakeContentFilesServer([$volume->fs_path => 'directory']);

    runComposeStart($this);

    contentFilesAssertNoWrite();
    expect($this->logEntries)->toContain([
        "Warning: A directory that is not empty is at {$volume->fs_path} on the server. Coolify did not write the configuration file for /etc/app.conf. Remove the directory on the server, then start the resource again.",
        'stderr',
    ])
        ->and(ContentFilesUpRecorder::indexOf('deployment', ' up -d'))->not->toBeNull();
});

test('a Compose deployment does not write storages without Coolify content', function (array $attributes) {
    contentFilesVolume($this->application, 'config/app.conf', $attributes);
    fakeContentFilesServer([]);

    runComposeStart($this);

    expect(contentFilesStateChecks())->toBe([]);
    contentFilesAssertNoWrite();
})->with([
    'host file' => [['is_host_file' => true]],
    'file from the Git repository' => [['is_based_on_git' => true]],
    'directory' => [['is_directory' => true, 'content' => null]],
    'file without content' => [['content' => null]],
    'file with empty content' => [['content' => '']],
    'binary file placeholder' => [['content' => LocalFileVolume::BINARY_PLACEHOLDER]],
    'too large file placeholder' => [['content' => LocalFileVolume::TOO_LARGE_PLACEHOLDER]],
]);

test('a pull request deployment writes the preview content file path', function () {
    $volume = contentFilesVolume($this->application, 'config/app.conf-pr-7', ['is_preview_suffix_enabled' => true]);
    fakeContentFilesServer([$volume->fs_path => 'missing']);

    runComposeStart($this, ['pull_request_id' => 7]);

    $write = ContentFilesUpRecorder::indexOf('ssh', 'tee '.escapeshellarg($this->application->workdir().'/config/app.conf-pr-7'));
    expect($write)->not->toBeNull()
        ->and($write)->toBeLessThan(ContentFilesUpRecorder::indexOf('deployment', ' up -d'));
});

test('a preserve-repository deployment writes content files before docker compose up', function () {
    $this->application->settings()->update(['is_preserve_repository_enabled' => true]);
    $volume = contentFilesVolume($this->application, 'config/app.conf');
    fakeContentFilesServer([$volume->fs_path => 'missing']);

    runComposeStart($this, ['preserveRepository' => true]);

    $write = ContentFilesUpRecorder::indexOf('ssh', 'tee '.escapeshellarg($volume->fs_path));
    expect($write)->not->toBeNull()
        ->and($write)->toBeLessThan(ContentFilesUpRecorder::indexOf('deployment', ' up -d'));
});

test('a deployment to an additional server writes missing content files on that server', function () {
    $volume = contentFilesVolume($this->application, 'config/app.conf');
    $otherServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->server->private_key_id,
    ])->fresh();
    fakeContentFilesServer([$volume->fs_path => 'missing']);

    runComposeStart($this, ['server' => $otherServer, 'mainServer' => $otherServer]);

    $write = ContentFilesUpRecorder::indexOf('ssh', 'base64 -d | tee '.escapeshellarg($volume->fs_path));
    expect(contentFilesStateChecks())->toHaveCount(1)
        ->and(contentFilesStateChecks()[0])->toContain("@'{$otherServer->ip}'")
        ->and($write)->not->toBeNull()
        ->and(ContentFilesUpRecorder::ssh()[$write])->toContain("@'{$otherServer->ip}'")
        ->and(ContentFilesUpRecorder::ssh()[$write])->not->toContain("@'{$this->server->ip}'");
});

test('a Dockerfile deployment to an additional server writes missing content files before the container starts', function () {
    $this->application->update(['build_pack' => 'dockerfile', 'docker_compose_location' => null]);
    $volume = contentFilesVolume($this->application, 'config/app.conf');
    $otherServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->server->private_key_id,
    ])->fresh();
    fakeContentFilesServer([$volume->fs_path => 'missing']);

    $job = new ContentFilesUpDeploymentJob;
    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message, string $type = 'stdout') {
        $this->logEntries[] = [$message, $type];
    });
    foreach ([
        'application' => $this->application->fresh(),
        'application_deployment_queue' => $queue,
        'server' => $otherServer,
        'mainServer' => $otherServer,
        'deployment_uuid' => 'deployment-uuid',
        'workdir' => '/artifacts/deployment-uuid',
        'configuration_dir' => $this->application->workdir(),
        'docker_compose_location' => '/docker-compose.yaml',
        'coolify_variables' => '',
        'pull_request_id' => 0,
        'preserveRepository' => false,
        'use_build_server' => false,
        'saved_outputs' => collect(),
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    (new ReflectionMethod(ApplicationDeploymentJob::class, 'start_by_compose_file'))->invoke($job);

    $write = ContentFilesUpRecorder::indexOf('ssh', 'base64 -d | tee '.escapeshellarg($volume->fs_path));
    $up = ContentFilesUpRecorder::indexOf('deployment', ' up --build -d');
    expect($write)->not->toBeNull()
        ->and($up)->not->toBeNull()
        ->and($write)->toBeLessThan($up)
        ->and(ContentFilesUpRecorder::ssh()[$write])->toContain("@'{$otherServer->ip}'")
        ->and($this->logEntries)->toContain(['Writing 1 missing configuration file.', 'stdout']);
});

test('the content file check and write are safe for non-root servers', function () {
    $this->server->update(['user' => 'ubuntu']);
    $volume = contentFilesVolume($this->application, 'config/app.conf');
    fakeContentFilesServer([$volume->fs_path => 'empty-directory']);

    runComposeStart($this, ['server' => $this->server->fresh(), 'mainServer' => $this->server->fresh()]);

    $escapedPath = escapeshellarg($volume->fs_path);
    expect(contentFilesStateChecks())->not->toBeEmpty()
        ->and(contentFilesStateChecks()[0])->toContain("sudo bash -c 'sh -c ");
    Process::assertRan(fn ($process) => str_contains($process->command, "sudo rmdir {$escapedPath}")
        && str_contains($process->command, "| sudo tee {$escapedPath}"));
});

test('starting a service writes missing content files before docker compose up', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => CONTENT_FILES_UP_SERVICE_COMPOSE,
    ]);
    fakeContentFilesServer([]);
    $service->parse();
    $appConf = LocalFileVolume::query()->where('mount_path', '/etc/app.conf')->firstOrFail();
    $workerConf = LocalFileVolume::query()->where('mount_path', '/etc/worker.conf')->firstOrFail();
    fakeContentFilesServer([$appConf->fs_path => 'missing', $workerConf->fs_path => 'directory']);

    $activity = StartService::run($service->fresh());

    $command = RemoteProcessCommand::read($activity);
    expect(contentFilesStateChecks())->toHaveCount(1)
        ->and($command)->toContain("echo 'Writing 1 missing configuration file.'")
        ->and($command)->toContain("echo 'Warning: A directory that is not empty is at {$workerConf->fs_path} on the server.")
        ->and(strpos($command, 'Writing 1 missing configuration file.'))->toBeLessThan(strpos($command, ' up -d'))
        ->and($command)->not->toContain(base64_encode('listen 8080;'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'base64 -d | tee '.escapeshellarg($appConf->fs_path))
        && str_contains($process->command, base64_encode('listen 8080;')));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'tee '.escapeshellarg($workerConf->fs_path)));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'rm -fr'));
});

test('deploying one service application writes only its missing content files', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => CONTENT_FILES_UP_SERVICE_COMPOSE,
    ]);
    fakeContentFilesServer([]);
    $service->parse();
    $appConf = LocalFileVolume::query()->where('mount_path', '/etc/app.conf')->firstOrFail();
    $workerConf = LocalFileVolume::query()->where('mount_path', '/etc/worker.conf')->firstOrFail();
    fakeContentFilesServer([$appConf->fs_path => 'missing', $workerConf->fs_path => 'missing']);

    $activity = DeployServiceApplication::run($service->applications()->where('name', 'app')->firstOrFail());

    $command = RemoteProcessCommand::read($activity);
    expect(contentFilesStateChecks())->toHaveCount(1)
        ->and(contentFilesStateChecks()[0])->not->toContain(escapeshellarg($workerConf->fs_path))
        ->and(strpos($command, 'Writing 1 missing configuration file.'))->toBeLessThan(strpos($command, ' up -d'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'tee '.escapeshellarg($appConf->fs_path)));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'tee '.escapeshellarg($workerConf->fs_path)));
});

test('starting a service without content files does not run the file check', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n",
    ]);
    fakeContentFilesServer([]);

    $activity = StartService::run($service);

    expect(contentFilesStateChecks())->toBe([])
        ->and($activity)->toBeInstanceOf(Activity::class)
        ->and(RemoteProcessCommand::read($activity))->not->toContain('missing configuration file');
});

test('the content file step continues when the server does not report a state', function () {
    $volume = contentFilesVolume($this->application, 'config/app.conf');
    fakeContentFilesServer([]);
    $messages = [];

    $written = EnsureContentFilesOnServer::run(
        [$volume],
        $this->server,
        function (string $message, string $type) use (&$messages) {
            $messages[] = [$message, $type];
        },
    );

    expect($written)->toBe(0)
        ->and($messages)->toBe([[
            "Warning: Coolify cannot check the configuration file {$volume->fs_path} on the server.",
            'stderr',
        ]]);
    contentFilesAssertNoWrite();
});

test('the storage save job writes the content again when the same file exists', function () {
    $volume = contentFilesVolume($this->application, 'config/app.conf');
    fakeContentFilesServer([$volume->fs_path => 'file']);

    (new ServerStorageSaveJob($volume))->handle();
    (new ServerStorageSaveJob($volume->fresh()))->handle();

    $writes = array_filter(ContentFilesUpRecorder::ssh(), fn (string $command) => str_contains($command, 'base64 -d | tee '.escapeshellarg($volume->fs_path)));
    expect($writes)->toHaveCount(2);
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'rm -fr'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'rmdir'));
});

test('the storage save job replaces an empty directory with the content file', function () {
    $volume = contentFilesVolume($this->application, 'config/app.conf');
    fakeContentFilesServer([$volume->fs_path => 'empty-directory']);

    (new ServerStorageSaveJob($volume))->handle();

    $escapedPath = escapeshellarg($volume->fs_path);
    Process::assertRan(fn ($process) => str_contains($process->command, "rmdir {$escapedPath}")
        && str_contains($process->command, base64_encode(CONTENT_FILES_UP_CONTENT))
        && strpos($process->command, "rmdir {$escapedPath}") < strpos($process->command, "tee {$escapedPath}"));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'rm -fr'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, "touch {$escapedPath}"));
});

test('the storage save job does not delete a directory that is not empty', function () {
    $volume = contentFilesVolume($this->application, 'config/app.conf');
    fakeContentFilesServer([$volume->fs_path => 'directory']);

    expect(fn () => (new ServerStorageSaveJob($volume))->handle())
        ->toThrow(Exception::class, "The following file is a directory on the server, but you are trying to mark it as a file: {$volume->fs_path}");

    contentFilesAssertNoWrite();
    expect($volume->fresh()->is_directory)->toBeFalse();
});

test('the server file sync keeps the content of a file storage when a directory is at its path', function () {
    $volume = contentFilesVolume($this->application, 'conf/app.conf');
    fakeContentFilesServer([$volume->fs_path => 'directory']);

    getFilesystemVolumesFromServer($this->application->fresh(), isInit: true);

    expect($volume->fresh())
        ->is_directory->toBeFalsy()
        ->content->toBe(CONTENT_FILES_UP_CONTENT);
    contentFilesAssertNoWrite();
});

test('the server file sync replaces an empty directory with the content file', function () {
    $volume = contentFilesVolume($this->application, 'conf/app.conf');
    fakeContentFilesServer([$volume->fs_path => 'empty-directory']);

    getFilesystemVolumesFromServer($this->application->fresh(), isInit: true);

    expect($volume->fresh())
        ->is_directory->toBeFalsy()
        ->content->toBe(CONTENT_FILES_UP_CONTENT);
    Process::assertRan(fn ($process) => str_contains($process->command, 'rmdir'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'base64 -d'));
});

test('the server file sync still marks a storage without content as a directory', function () {
    $volume = contentFilesVolume($this->application, 'data/cache', ['content' => null]);
    fakeContentFilesServer([$volume->fs_path => 'directory']);

    getFilesystemVolumesFromServer($this->application->fresh(), isInit: true);

    expect($volume->fresh()->is_directory)->toBeTruthy();
});

test('starting a service runs its commands on the deployment queue', function (bool $selfHosted, string $queue) {
    config(['constants.coolify.self_hosted' => $selfHosted]);
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n",
    ]);
    fakeContentFilesServer([]);

    StartService::run($service);

    Bus::assertDispatched(CoolifyTask::class, fn (CoolifyTask $job) => $job->queue === $queue);
})->with([
    'cloud' => [false, 'deployments'],
    'self-hosted' => [true, 'high'],
]);
