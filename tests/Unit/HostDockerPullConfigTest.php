<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

class HostDockerPullRecordingJob extends ApplicationDeploymentJob
{
    public array $recordedCommands = [];

    public function __construct() {}

    public function execute_remote_command(...$commands): void
    {
        foreach ($commands as $command) {
            $this->recordedCommands[] = $command['command'] ?? $command[0];
        }
    }
}

function hostDockerPullServer(string $user): Server
{
    $server = new Server;
    $server->id = 1;
    $server->user = $user;

    return $server;
}

function hostDockerPullJob(Server $server, string $buildPack = 'dockerfile'): array
{
    $job = new HostDockerPullRecordingJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-uuid';
    $application->name = 'application';
    $application->build_pack = $buildPack;
    $application->docker_registry_image_name = 'ghcr.io/org/app';
    $application->setRelation('settings', new ApplicationSetting(['is_raw_compose_deployment_enabled' => false]));
    $application->setRelation('fileStorages', new Collection);
    $application->shouldReceive('workdir')->andReturn('/data/coolify/applications/application-uuid');

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('getAttribute')->with('updated_at')->andReturn('2026-10-19 00:00:00');
    $queue->shouldReceive('addLogEntry')->andReturnNull();

    $destination = Mockery::mock(StandaloneDocker::class)->makePartial();
    $destination->shouldReceive('proxyConnectCommand')->andReturn('docker network connect coolify coolify-proxy');

    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'destination' => $destination,
        'server' => $server,
        'build_server' => hostDockerPullServer('root'),
        'mainServer' => $server,
        'use_build_server' => true,
        'is_this_additional_server' => true,
        'preserveRepository' => true,
        'workdir' => '/artifacts/deployment-uuid',
        'configuration_dir' => '/data/coolify/applications/application-uuid',
        'pull_request_id' => 0,
        'deployment_uuid' => 'deployment-uuid',
        'docker_compose_location' => '/docker-compose.yaml',
        'coolify_variables' => "COOLIFY_URL='https://example.com' COOLIFY_RESOURCE_UUID='application-uuid' ",
        'docker_compose_custom_build_command' => null,
        'generated_docker_compose' => "services:\n  db:\n    image: 'ghcr.io/org/db:1'\n",
        'production_image_name' => 'ghcr.io/org/app:commit',
        'saved_outputs' => new Collection(['local_image_found' => 'sha256:image']),
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    return [$job, $reflection];
}

/**
 * Runs a command as ExecuteRemoteCommand sends it to the server, with a sudo that resets HOME like env_reset,
 * and returns the Docker config directory that each docker call reads, followed by the Docker context that it
 * selects. The SSH user's home is shown as /home/administrator.
 */
function hostDockerConfigDirectoryOf(string $command, Server $server, bool $sshUserHasDockerConfig = true, bool $withContext = false): string
{
    $directory = sys_get_temp_dir().'/host-docker-pull-'.uniqid();
    mkdir("{$directory}/bin", recursive: true);
    mkdir("{$directory}/home/.docker", recursive: true);
    file_put_contents("{$directory}/bin/docker", "#!/bin/sh\nprintf '%s\\n' \"\${DOCKER_CONFIG:-\$HOME/.docker}\"\n".($withContext ? "printf '%s\\n' \"\${DOCKER_CONTEXT:-config.json}\"\n" : ''));
    chmod("{$directory}/bin/docker", 0755);
    if ($sshUserHasDockerConfig) {
        file_put_contents("{$directory}/home/.docker/config.json", '{"auths":{},"currentContext":"rootless"}');
    }

    if ($server->isNonRoot()) {
        $command = parseLineForSudo($command, $server);
    }
    $script = "sudo() { env -i HOME=/root PATH=\"\$PATH\" \"\$@\"; }\n{$command}";
    $process = new Process(['sh', '-c', $script], env: ['HOME' => "{$directory}/home", 'PATH' => "{$directory}/bin:".getenv('PATH')]);
    $process->run();

    (new Filesystem)->deleteDirectory($directory);

    expect($process->getErrorOutput())->toBe('');

    return str_replace("{$directory}/home", '/home/administrator', trim($process->getOutput()));
}

afterEach(function () {
    Mockery::close();
});

$pulls = [
    'build server container start' => ['start_by_compose_file', ' up --pull always --build -d'],
    'compose image pull before a build server deployment' => ['pull_docker_compose_images', ' pull --ignore-buildable '],
    'additional server image pull' => ['pull_image_for_additional_server', 'docker pull '],
];

it('pulls with the SSH user Docker config, where the registry login is, on a non-root server', function (string $method, string $pull) {
    $server = hostDockerPullServer('administrator');
    [$job, $reflection] = hostDockerPullJob($server);

    $reflection->getMethod($method)->invoke($job);

    $command = collect($job->recordedCommands)->first(fn (string $command) => str_contains($command, $pull));
    expect($command)->not->toBeNull()
        ->and(hostDockerConfigDirectoryOf($command, $server))->toBe('/home/administrator/.docker');
})->with($pulls);

it('pulls on the host daemon when the SSH user Docker config selects another context', function (string $method, string $pull) {
    $server = hostDockerPullServer('administrator');
    [$job, $reflection] = hostDockerPullJob($server);

    $reflection->getMethod($method)->invoke($job);

    $command = collect($job->recordedCommands)->first(fn (string $command) => str_contains($command, $pull));
    expect($command)->not->toBeNull()
        ->and(hostDockerConfigDirectoryOf($command, $server, withContext: true))->toBe("/home/administrator/.docker\ndefault");
})->with($pulls);

it('keeps the root Docker config on a non-root server when the SSH user has no Docker config', function (string $method, string $pull) {
    $server = hostDockerPullServer('administrator');
    [$job, $reflection] = hostDockerPullJob($server);

    $reflection->getMethod($method)->invoke($job);

    $command = collect($job->recordedCommands)->first(fn (string $command) => str_contains($command, $pull));
    expect($command)->not->toBeNull()
        ->and(hostDockerConfigDirectoryOf($command, $server, sshUserHasDockerConfig: false))->toBe('/root/.docker');
})->with($pulls);

it('keeps the pull commands unchanged on a root server', function (string $method, string $pull) {
    $server = hostDockerPullServer('root');
    [$job, $reflection] = hostDockerPullJob($server);

    $reflection->getMethod($method)->invoke($job);

    $command = collect($job->recordedCommands)->first(fn (string $command) => str_contains($command, $pull));
    expect($command)->not->toContain('DOCKER_CONFIG');
})->with($pulls);

it('checks the registry for a missing image with the SSH user Docker config on a non-root server', function () {
    $server = hostDockerPullServer('administrator');
    [$job, $reflection] = hostDockerPullJob($server);
    $reflection->getProperty('saved_outputs')->setValue($job, new Collection);

    $reflection->getMethod('check_image_locally_or_remotely')->invoke($job);

    $command = collect($job->recordedCommands)->first(fn (string $command) => str_contains($command, 'docker pull '));
    expect($command)->not->toBeNull()
        ->and(hostDockerConfigDirectoryOf($command, $server))->toBe('/home/administrator/.docker');
});
