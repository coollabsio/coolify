<?php

use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

const INCLUDE_TEST_PREVIOUS_COMPOSE = "services:\n  old:\n    image: nginx\n";

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);
    Server::flushIdentityMap();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'build_pack' => 'dockercompose',
        'git_repository' => 'https://github.com/coollabsio/compose-app',
        'git_branch' => 'main',
        'base_directory' => '/',
        'docker_compose_location' => '/docker-compose.yml',
        'docker_compose_raw' => INCLUDE_TEST_PREVIOUS_COMPOSE,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ])->fresh();
    $this->serverCommands = new ArrayObject;
});

/**
 * A server with the repository files in $files. `docker compose config` prints $flattened.
 *
 * @param  array<string, string>  $files
 */
function fakeIncludeRepository(array $files, string $flattened = ''): void
{
    $log = test()->serverCommands;
    Process::fake(function ($process) use ($files, $flattened, $log) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $log[] = $command;
        if (str_contains($command, 'git --version')) {
            return Process::result(output: 'git version 2.43.0');
        }
        if (str_contains($command, 'head -c')) {
            return Process::result(output: $files['docker-compose.yml']);
        }
        if (str_contains($command, 'docker compose')) {
            return Process::result(output: $flattened);
        }
        foreach ($files as $path => $content) {
            if (str_contains($command, "HEAD:{$path}'")) {
                return Process::result(output: $content);
            }
        }
        if (str_contains($command, 'git show')) {
            return Process::result(errorOutput: "fatal: path does not exist in 'HEAD'", exitCode: 128);
        }

        return Process::result(output: '');
    });
}

/**
 * @return list<string>
 */
function includeTestServerCommands(): array
{
    return test()->serverCommands->getArrayCopy();
}

/**
 * @return list<string>
 */
function includeTestComposeConfigCommands(): array
{
    return array_values(array_filter(includeTestServerCommands(), fn (string $command) => str_contains($command, 'docker compose')));
}

it('flattens included files with docker compose config and saves the result', function () {
    fakeIncludeRepository([
        'docker-compose.yml' => "include:\n  - ./backend/compose.yml\nservices:\n  web:\n    image: nginx\n    depends_on: [api]\n",
        'backend/compose.yml' => "include:\n  - path: ../shared/db.yml\n    env_file: ../shared/db.env\nservices:\n  api:\n    build: .\n",
        'shared/db.yml' => "services:\n  db:\n    image: postgres\n",
    ], <<<'YAML'
services:
  api:
    build:
      context: backend
    volumes:
      - bind:
          create_host_path: true
        source: backend/data
        target: /data
        type: bind
  db:
    image: postgres
  web:
    image: nginx
    environment:
      - SERVICE_FQDN_WEB_80
    volumes:
      - bind:
          create_host_path: true
        source: ./web-data
        target: /web
        type: bind
YAML);

    $this->application->loadComposeFile();

    $raw = $this->application->refresh()->docker_compose_raw;
    expect($raw)->not->toContain('include')
        ->and(array_keys(Yaml::parse($raw)['services']))->toBe(['api', 'db', 'web'])
        ->and(Yaml::parse($raw)['services']['api']['volumes'][0]['source'])->toBe('./backend/data')
        ->and(Yaml::parse($raw)['services']['web']['volumes'][0]['source'])->toBe('./web-data');

    $config = includeTestComposeConfigCommands();
    expect($config)->toHaveCount(1)
        ->and($config[0])->toContain('config --no-interpolate --no-path-resolution --no-normalize')
        ->and($config[0])->toContain('COMPOSE_EXPERIMENTAL_GIT_REMOTE=0')
        ->and($config[0])->toContain('COMPOSE_EXPERIMENTAL_OCI_REMOTE=0');

    $copies = implode("\n", includeTestServerCommands());
    expect($copies)->toContain('HEAD:backend/compose.yml')
        ->toContain('HEAD:shared/db.yml')
        ->toContain('shared/db.env');
});

it('does not run docker compose config when the file has no include section', function () {
    fakeIncludeRepository(['docker-compose.yml' => "services:\n  web:\n    image: nginx\n"]);

    $this->application->loadComposeFile();

    expect($this->application->refresh()->docker_compose_raw)->toBe("services:\n  web:\n    image: nginx")
        ->and(includeTestComposeConfigCommands())->toBe([]);
});

it('rejects an included file outside the repository and keeps the saved Compose file', function (array $files) {
    fakeIncludeRepository($files);

    expect(fn () => $this->application->loadComposeFile())
        ->toThrow(DeploymentException::class, 'The Docker Compose file at /docker-compose.yml (branch: main) has an include that Coolify cannot use');

    expect($this->application->refresh()->docker_compose_raw)->toBe(INCLUDE_TEST_PREVIOUS_COMPOSE)
        ->and(includeTestComposeConfigCommands())->toBe([]);
})->with([
    'parent directory in the main file' => [['docker-compose.yml' => "include:\n  - ../other/compose.yml\n"]],
    'absolute path in a nested file' => [[
        'docker-compose.yml' => "include:\n  - a/compose.yml\n",
        'a/compose.yml' => "include:\n  - /data/coolify/applications/x/docker-compose.yaml\n",
    ]],
    'git remote' => [['docker-compose.yml' => "include:\n  - https://github.com/acme/stack.git#main:compose.yml\n"]],
    'project directory outside the repository' => [['docker-compose.yml' => "include:\n  - path: a.yml\n    project_directory: ../..\n"]],
]);

it('rejects a symlinked included file', function () {
    // git show prints the link target of a symlink, which is not a Compose mapping.
    fakeIncludeRepository([
        'docker-compose.yml' => "include:\n  - linked.yml\n",
        'linked.yml' => '/data/coolify/applications/other/docker-compose.yaml',
    ]);

    expect(fn () => $this->application->loadComposeFile())
        ->toThrow(DeploymentException::class, 'linked.yml is not a Docker Compose file');

    expect(includeTestComposeConfigCommands())->toBe([]);
});

it('validates the flattened file like any other Compose file', function () {
    fakeIncludeRepository([
        'docker-compose.yml' => "include:\n  - evil.yml\n",
        'evil.yml' => "services:\n  'web;touch /tmp/pwned':\n    image: nginx\n",
    ], "services:\n  'web;touch /tmp/pwned':\n    image: nginx\n");

    expect(fn () => $this->application->loadComposeFile())
        ->toThrow(DeploymentException::class, 'is not safe to use');

    expect($this->application->refresh()->docker_compose_raw)->toBe(INCLUDE_TEST_PREVIOUS_COMPOSE);
});
