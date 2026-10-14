<?php

use App\Actions\Application\StopApplication;
use App\Actions\Application\StopApplicationOneServer;
use App\Actions\Application\StopApplicationPreview;
use App\Actions\Service\RestartServiceApplication;
use App\Actions\Service\StopService;
use App\Actions\Service\StopServiceApplication;
use App\Events\ScheduledTaskDone;
use App\Events\ServiceStatusChanged;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/**
 * Container names come from the database (service names) or from `docker ps`. Coolify quotes them
 * in shell commands, also when the non-root sudo parser adds `sudo`.
 */
const QUOTING_HOSTILE_NAME = 'app;touch /tmp/pwned';

beforeEach(function () {
    // StandaloneDocker::server() uses the Server identity map; a map from an earlier test has a stale SSH user.
    Server::flushIdentityMap();
    Bus::fake();
    Event::fake([ServiceStatusChanged::class, ScheduledTaskDone::class]);
    Notification::fake();
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
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false, 'docker_version' => '28.0.0']);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n",
    ]);
    $this->serviceApplication = ServiceApplication::create([
        'name' => QUOTING_HOSTILE_NAME,
        'service_id' => $this->service->id,
        'status' => 'running:healthy',
    ]);
    $this->containerName = escapeshellarg(QUOTING_HOSTILE_NAME.'-'.$this->service->uuid);

    Process::fake(fn () => Process::result(output: 'done'));
});

function quotingUseNonRootUser(): void
{
    test()->server->update(['user' => 'ubuntu']);
    test()->server->refresh();
    test()->service->refresh();
    test()->serviceApplication->refresh();
}

function quotingAssertCommandRan(string $command): void
{
    Process::assertRan(fn ($process) => str_contains($process->command, $command));
}

function quotingAssertNoUnquotedName(): void
{
    Process::assertDidntRun(fn ($process) => str_contains($process->command, ' app;touch'));
}

test('stopping a service application quotes its container name', function (bool $nonRoot, string $sudo) {
    if ($nonRoot) {
        quotingUseNonRootUser();
    }

    StopServiceApplication::run($this->serviceApplication);
    StopServiceApplication::run($this->serviceApplication->refresh(), removeContainer: true);

    quotingAssertCommandRan("\n{$sudo}docker stop {$this->containerName}\n");
    quotingAssertCommandRan("\n{$sudo}docker rm -f {$this->containerName}\n");
    quotingAssertNoUnquotedName();
})->with(['root' => [false, ''], 'non-root' => [true, 'sudo ']]);

test('restarting a service application quotes its container name', function (bool $nonRoot, string $sudo) {
    if ($nonRoot) {
        quotingUseNonRootUser();
    }

    RestartServiceApplication::run($this->serviceApplication);

    quotingAssertCommandRan("\n{$sudo}docker restart {$this->containerName}\n");
    quotingAssertNoUnquotedName();
})->with(['root' => [false, ''], 'non-root' => [true, 'sudo ']]);

test('stopping a service quotes every container name', function (bool $nonRoot, string $sudo) {
    if ($nonRoot) {
        quotingUseNonRootUser();
    }
    ServiceDatabase::create(['name' => 'db', 'service_id' => $this->service->id]);
    $databaseContainer = escapeshellarg('db-'.$this->service->uuid);

    StopService::run($this->service, dockerCleanup: false);

    quotingAssertCommandRan("{$sudo}docker stop --timeout=30 {$this->containerName} {$databaseContainer}\n");
    quotingAssertCommandRan("\n{$sudo}docker rm -f {$this->containerName} {$databaseContainer}\n");
    quotingAssertNoUnquotedName();
})->with(['root' => [false, ''], 'non-root' => [true, 'sudo ']]);

test('a scheduled task in a service quotes the container name', function (bool $nonRoot, string $sudo) {
    if ($nonRoot) {
        quotingUseNonRootUser();
    }
    $task = ScheduledTask::factory()->create([
        'team_id' => $this->team->id,
        'service_id' => $this->service->id,
        'container' => QUOTING_HOSTILE_NAME,
        'command' => "echo 'hello'",
    ]);

    (new ScheduledTaskJob($task))->handle();

    quotingAssertCommandRan("{$sudo}docker exec {$this->containerName} sh -c 'echo '\\''hello'\\''' 2>&1 |");
    quotingAssertNoUnquotedName();
    expect($task->executions()->sole()->status)->toBe('success');
})->with(['root' => [false, ''], 'non-root' => [true, 'sudo ']]);

/**
 * `docker ps` returns one container for the application.
 */
function quotingFakeApplicationContainer(string $name): void
{
    Process::fake(function ($process) use ($name) {
        if (str_contains($process->command, 'docker ps -a --filter')) {
            return Process::result(output: json_encode(['Names' => $name, 'Labels' => 'coolify.applicationId=1', 'State' => 'running']));
        }

        return Process::result(output: '');
    });
}

function quotingApplication(): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
}

test('stopping an application quotes the container names from docker ps', function (bool $nonRoot, string $sudo) {
    if ($nonRoot) {
        quotingUseNonRootUser();
    }
    $application = quotingApplication();
    quotingFakeApplicationContainer(QUOTING_HOSTILE_NAME);
    $quoted = escapeshellarg(QUOTING_HOSTILE_NAME);

    StopApplication::run($application, dockerCleanup: false);

    quotingAssertCommandRan("{$sudo}docker stop --timeout=");
    Process::assertRan(fn ($process) => preg_match('/docker stop --timeout=\d+ '.preg_quote($quoted, '/').'\n/', $process->command) === 1);
    quotingAssertCommandRan("\n{$sudo}docker rm -f {$quoted}\n");
    quotingAssertNoUnquotedName();
})->with(['root' => [false, ''], 'non-root' => [true, 'sudo ']]);

test('stopping an application preview quotes the container names from docker ps', function () {
    $application = quotingApplication();
    $preview = ApplicationPreview::forceCreate([
        'application_id' => $application->id,
        'pull_request_id' => 1,
        'pull_request_html_url' => 'https://example.com/pull/1',
    ]);
    Process::fake(function ($process) {
        if (str_contains($process->command, 'docker ps -a --filter')) {
            return Process::result(output: json_encode(['Names' => QUOTING_HOSTILE_NAME, 'Labels' => 'coolify.applicationId=1,coolify.pullRequestId=1']));
        }

        return Process::result(output: '');
    });
    $quoted = escapeshellarg(QUOTING_HOSTILE_NAME);

    StopApplicationPreview::run($preview);

    Process::assertRan(fn ($process) => preg_match('/docker stop --timeout=\d+ '.preg_quote($quoted, '/').'\n/', $process->command) === 1);
    quotingAssertCommandRan("\ndocker rm -f {$quoted}\n");
    quotingAssertNoUnquotedName();
});

test('stopping an application on one server quotes the container names from docker ps', function () {
    $application = quotingApplication();
    quotingFakeApplicationContainer(QUOTING_HOSTILE_NAME);
    $quoted = escapeshellarg(QUOTING_HOSTILE_NAME);

    StopApplicationOneServer::run($application, $this->server);

    Process::assertRan(fn ($process) => preg_match('/docker stop --timeout=\d+ '.preg_quote($quoted, '/').'\n/', $process->command) === 1);
    quotingAssertNoUnquotedName();
});

test('the non-root sudo parser keeps quoted container names as one argument', function () {
    $server = new Server(['user' => 'ubuntu']);
    $name = escapeshellarg(QUOTING_HOSTILE_NAME.'-uuid');

    expect(parseCommandsByLineForSudo(collect([
        "docker stop {$name}",
        "docker restart {$name}",
        "docker rm -f {$name} 'db-uuid'",
        dockerStopCommand(30, "{$name} 'db-uuid'", '28.0.0'),
    ]), $server))->toBe([
        "sudo docker stop {$name}",
        "sudo docker restart {$name}",
        "sudo docker rm -f {$name} 'db-uuid'",
        "sudo docker stop --timeout=30 {$name} 'db-uuid'",
    ]);
});
