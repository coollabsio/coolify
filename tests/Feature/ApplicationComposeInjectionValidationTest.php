<?php

use App\Actions\Application\LoadComposeFile;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const SAFE_APPLICATION_COMPOSE = "services:\n  web:\n    image: nginx:alpine\n";

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('compose-injection-test', ['*'])->plainTextToken;

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'git_repository' => 'https://github.com/coollabsio/compose-app',
        'git_branch' => 'main',
        'base_directory' => '/',
        'docker_compose_location' => '/docker-compose.yml',
        'docker_compose_raw' => SAFE_APPLICATION_COMPOSE,
        'redirect' => 'both',
        'static_image' => 'nginx:alpine',
    ])->fresh();
});

/**
 * @return array<string, array{string}>
 */
function composeInjectionPayloads(): array
{
    return [
        'service name command substitution' => ["services:\n  web\$(touch /tmp/pwned):\n    image: nginx\n"],
        'service name command separator' => ["services:\n  'web;touch /tmp/pwned':\n    image: nginx\n"],
        'network name command substitution' => ["services:\n  web:\n    image: nginx\n    networks:\n      - edge\nnetworks:\n  edge:\n    name: 'edge\$(touch /tmp/pwned)'\n"],
        'volume source command separator' => ["services:\n  web:\n    image: nginx\n    volumes:\n      - './data;touch /tmp/pwned:/data'\n"],
    ];
}

/**
 * The server returns $compose when Coolify reads the Compose file from the repository.
 */
function fakeRepositoryCompose(string $compose): void
{
    Process::fake(function ($process) use ($compose) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        if (str_contains($command, 'git --version')) {
            return Process::result(output: 'git version 2.43.0');
        }
        if (str_contains($command, 'head -c')) {
            return Process::result(output: $compose);
        }

        return Process::result(output: '');
    });
}

/**
 * A deployment job that only has the state needed to load the Compose file.
 *
 * @param  array<int, array{0: string, 1: string}>  $logEntries
 */
function composeDeploymentJob(Application $application, int $pullRequestId, array &$logEntries): ApplicationDeploymentJob
{
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message, string $type = 'stdout', bool $hidden = false) use (&$logEntries) {
        $logEntries[] = [$message, $type, $hidden];
    });

    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'pull_request_id' => $pullRequestId,
        'deployment_uuid' => 'deployment-uuid',
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    return $job;
}

test('loading the Compose file from Git rejects injection and does not save it', function (string $compose) {
    fakeRepositoryCompose($compose);

    expect(fn () => $this->application->loadComposeFile())
        ->toThrow(DeploymentException::class, 'The Docker Compose file at /docker-compose.yml (branch: main) is not safe to use');

    expect($this->application->refresh()->docker_compose_raw)->toBe(SAFE_APPLICATION_COMPOSE);
})->with(composeInjectionPayloads());

test('the rejection message is HTML-safe for error toasts', function () {
    fakeRepositoryCompose("services:\n  web:\n    image: nginx\n    volumes:\n      - './data>/etc/passwd:/data'\n");

    expect(fn () => $this->application->loadComposeFile())
        ->toThrow(function (DeploymentException $exception) {
            expect($exception->getMessage())->toContain('&gt;')->not->toContain("'>'");
        });
});

test('loading the Compose file from Git restores the previous location when validation fails', function () {
    fakeRepositoryCompose(composeInjectionPayloads()['service name command separator'][0]);
    $this->application->docker_compose_location = '/compose/evil.yml';

    expect(fn () => $this->application->loadComposeFile(restoreDockerComposeLocation: '/docker-compose.yml', restoreBaseDirectory: '/'))
        ->toThrow(DeploymentException::class);

    expect($this->application->refresh())
        ->docker_compose_location->toBe('/docker-compose.yml')
        ->docker_compose_raw->toBe(SAFE_APPLICATION_COMPOSE);
});

test('loading a safe Compose file from Git saves it', function () {
    $compose = "services:\n  api:\n    image: acme/api:\${TAG:-latest}\n    command: sh -c 'migrate && serve > /dev/null'\n";
    fakeRepositoryCompose($compose);

    $this->application->loadComposeFile();

    expect($this->application->refresh()->docker_compose_raw)->toBe(trim($compose));
});

test('loading a Compose file with a variable external volume name saves it', function () {
    $compose = "services:\n  web:\n    image: nginx\n    volumes:\n      - 'shared-data:/data'\nvolumes:\n  shared-data:\n    external: true\n    name: \${SHARED_VOLUME}\n";
    fakeRepositoryCompose($compose);

    $this->application->loadComposeFile();

    expect($this->application->fresh()->docker_compose_raw)->toBe(trim($compose));
});

test('the queued LoadComposeFile action rejects injection', function () {
    fakeRepositoryCompose(composeInjectionPayloads()['service name command substitution'][0]);

    expect(fn () => LoadComposeFile::run($this->application))->toThrow(DeploymentException::class);
    expect($this->application->refresh()->docker_compose_raw)->toBe(SAFE_APPLICATION_COMPOSE);
});

test('the Reload Compose File button shows the validation error and does not save', function (string $compose) {
    $this->actingAs($this->user);
    fakeRepositoryCompose($compose);

    Livewire::test(General::class, ['application' => $this->application])
        ->call('loadComposeFile')
        ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[0], 'is not safe to use'))
        ->assertNotDispatched('success')
        ->assertSet('dockerComposeRaw', SAFE_APPLICATION_COMPOSE);

    expect($this->application->refresh()->docker_compose_raw)->toBe(SAFE_APPLICATION_COMPOSE);
})->with(composeInjectionPayloads());

test('saving the General form rejects an injected raw Compose file', function (string $compose) {
    $this->actingAs($this->user);
    fakeRepositoryCompose(SAFE_APPLICATION_COMPOSE);

    Livewire::test(General::class, ['application' => $this->application])
        ->set('dockerComposeRaw', $compose)
        ->call('submit')
        ->assertDispatched('error', fn (string $event, array $params): bool => str_starts_with($params[0], 'Invalid Docker'))
        ->assertNotDispatched('success');

    expect($this->application->refresh()->docker_compose_raw)->toBe(SAFE_APPLICATION_COMPOSE);
})->with(composeInjectionPayloads());

test('an instant save of the General form rejects an injected raw Compose file', function () {
    $this->actingAs($this->user);
    fakeRepositoryCompose(SAFE_APPLICATION_COMPOSE);

    Livewire::test(General::class, ['application' => $this->application])
        ->set('dockerComposeRaw', composeInjectionPayloads()['service name command substitution'][0])
        ->set('isHttpBasicAuthEnabled', true)
        ->set('httpBasicAuthUsername', 'admin')
        ->set('httpBasicAuthPassword', 'password123')
        ->call('instantSave')
        ->assertDispatched('error', fn (string $event, array $params): bool => str_starts_with($params[0], 'Invalid Docker Compose service name'))
        ->assertNotDispatched('success');

    expect($this->application->refresh())
        ->docker_compose_raw->toBe(SAFE_APPLICATION_COMPOSE)
        ->is_http_basic_auth_enabled->toBeFalsy();
});

test('saving the General form accepts a safe raw Compose file', function () {
    $this->actingAs($this->user);
    $compose = "services:\n  web:\n    image: nginx:alpine\n    healthcheck:\n      test: ['CMD-SHELL', 'curl -f http://localhost || exit 1']\n";
    // Saving the form reloads the Compose file from the repository when it resets the labels.
    fakeRepositoryCompose($compose);

    Livewire::test(General::class, ['application' => $this->application])
        ->set('dockerComposeRaw', $compose)
        ->call('submit')
        // submit() also runs real DNS checks for the domains, so only Compose errors are relevant here.
        ->assertNotDispatched('error', fn (string $event, array $params): bool => str_contains((string) ($params[0] ?? ''), 'Docker Compose'));

    expect($this->application->refresh()->docker_compose_raw)->toBe(trim($compose));
});

test('API create rejects an injected docker_compose_raw and creates no application', function (string $compose) {
    Queue::fake();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->postJson('/api/v1/applications/public', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'git_repository' => 'https://gitlab.com/coolify/compose-app',
            'git_branch' => 'main',
            'build_pack' => 'dockercompose',
            'ports_exposes' => '80',
            'autogenerate_domain' => false,
            'docker_compose_raw' => $compose,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed.')
        ->assertJsonStructure(['errors' => ['docker_compose_raw']]);

    expect(Application::query()->where('git_repository', 'https://gitlab.com/coolify/compose-app')->exists())->toBeFalse();
})->with(composeInjectionPayloads());

test('API create accepts a safe docker_compose_raw', function () {
    Queue::fake();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->postJson('/api/v1/applications/public', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'git_repository' => 'https://gitlab.com/coolify/compose-app',
            'git_branch' => 'main',
            'build_pack' => 'dockercompose',
            'ports_exposes' => '80',
            'autogenerate_domain' => false,
            'docker_compose_raw' => SAFE_APPLICATION_COMPOSE,
        ])
        ->assertCreated();
});

test('API create does not generate a domain for a docker compose application', function () {
    Queue::fake();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->postJson('/api/v1/applications/public', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'git_repository' => 'https://gitlab.com/coolify/compose-app',
            'git_branch' => 'main',
            'build_pack' => 'dockercompose',
            'ports_exposes' => '80',
            'autogenerate_domain' => true,
            'docker_compose_raw' => SAFE_APPLICATION_COMPOSE,
        ])
        ->assertCreated();

    expect(Application::query()->where('uuid', $response->json('uuid'))->value('fqdn'))->toBeNull();
});

test('API update does not accept docker_compose_raw', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'docker_compose_raw' => composeInjectionPayloads()['service name command substitution'][0],
        ])
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['docker_compose_raw']]);

    expect($this->application->refresh()->docker_compose_raw)->toBe(SAFE_APPLICATION_COMPOSE);
});

test('a deployment stops with a visible log line when the repository Compose file is not safe', function (int $pullRequestId) {
    fakeRepositoryCompose(composeInjectionPayloads()['service name command substitution'][0]);
    $logEntries = [];
    $job = composeDeploymentJob($this->application, $pullRequestId, $logEntries);

    expect(fn () => (new ReflectionMethod(ApplicationDeploymentJob::class, 'loadComposeFileForDeployment'))->invoke($job))
        ->toThrow(DeploymentException::class, 'Deployment stopped: the Docker Compose file at /docker-compose.yml (branch: main) is not safe to use');

    $visible = collect($logEntries)->filter(fn (array $entry): bool => $entry[2] === false)->pluck(0)->implode("\n");
    expect($visible)
        ->toContain('Deployment stopped: the Docker Compose file at /docker-compose.yml (branch: main) is not safe to use')
        ->toContain('Invalid Docker Compose service name')
        ->not->toContain('&#039;')
        ->not->toContain('#0 ')
        ->not->toContain('.php');
    expect($this->application->refresh()->docker_compose_raw)->toBe(SAFE_APPLICATION_COMPOSE);
    Process::assertNotRan(fn ($process) => str_contains(is_array($process->command) ? implode(' ', $process->command) : $process->command, 'base64 -d'));
})->with(['production' => 0, 'pull request preview' => 42]);

test('the deployment log shows the unsafe Compose file explanation once and a short failure line', function () {
    fakeRepositoryCompose(composeInjectionPayloads()['service name command substitution'][0]);
    $logEntries = [];
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message, string $type = 'stdout', bool $hidden = false) use (&$logEntries) {
        $logEntries[] = [$message, $type, $hidden];
    });
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('failDeployment')->once();
    foreach ([
        'application' => $this->application,
        'application_deployment_queue' => $queue,
        'pull_request_id' => 0,
        'deployment_uuid' => 'deployment-uuid',
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    try {
        (new ReflectionMethod(ApplicationDeploymentJob::class, 'loadComposeFileForDeployment'))->invoke($job);
        $this->fail('The deployment did not stop.');
    } catch (DeploymentException $exception) {
        $job->failed($exception);
    }

    $visible = collect($logEntries)->filter(fn (array $entry): bool => $entry[2] === false)->pluck(0);
    $explanation = 'the Docker Compose file at /docker-compose.yml (branch: main) is not safe to use';
    expect($visible->filter(fn (string $line): bool => str_contains($line, $explanation)))->toHaveCount(1)
        ->and($visible)->toContain('Deployment failed.')
        ->and($visible->implode("\n"))->not->toContain('Deployment failed: ');

    $hidden = collect($logEntries)->filter(fn (array $entry): bool => $entry[2] === true)->pluck(0)->implode("\n");
    expect($hidden)->toContain('Error type: '.DeploymentException::class)
        ->toContain('Location: ');
});

test('a deployment loads a safe repository Compose file', function () {
    $compose = "services:\n  web:\n    image: nginx:1.27\n";
    fakeRepositoryCompose($compose);
    $logEntries = [];

    (new ReflectionMethod(ApplicationDeploymentJob::class, 'loadComposeFileForDeployment'))
        ->invoke(composeDeploymentJob($this->application, 0, $logEntries));

    expect($this->application->refresh()->docker_compose_raw)->toBe(trim($compose));
});

test('the Compose deployment validates the repository file before any command uses it', function () {
    $method = new ReflectionMethod(ApplicationDeploymentJob::class, 'deploy_docker_compose_buildpack');
    $lines = array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
    $body = implode('', $lines);

    $loadPosition = strpos($body, '$this->loadComposeFileForDeployment();');
    expect($loadPosition)->not->toBeFalse()
        ->and($body)->not->toContain('$this->application->loadComposeFile(')
        ->and($loadPosition)->toBeLessThan(strpos($body, '$this->application->oldRawParser()'))
        ->and($loadPosition)->toBeLessThan(strpos($body, '$this->parseComposeFileForDeployment()'))
        ->and($loadPosition)->toBeLessThan(strpos($body, 'base64 -d'))
        ->and($body)->toContain('"stat -c \'%F\' ".escapeshellarg($realPathInGit)');
});

test('loading a Compose file with a variable service network saves it', function () {
    $compose = "services:\n  web:\n    image: nginx\n    networks:\n      - \${NET:-proxy}\nnetworks:\n  proxy:\n    external: true\n";
    fakeRepositoryCompose($compose);

    $this->application->loadComposeFile();

    expect($this->application->fresh()->docker_compose_raw)->toBe(trim($compose));
});

test('the deployment log names the service and network that failed validation', function () {
    fakeRepositoryCompose("services:\n  web:\n    image: nginx\n    networks:\n      - '\$(id)'\n");
    $logEntries = [];
    $job = composeDeploymentJob($this->application, 0, $logEntries);

    expect(fn () => (new ReflectionMethod(ApplicationDeploymentJob::class, 'loadComposeFileForDeployment'))->invoke($job))
        ->toThrow(DeploymentException::class);

    expect(collect($logEntries)->pluck(0)->implode("\n"))
        ->toContain('Invalid Docker Compose service network "$(id)" in service web.');
});
