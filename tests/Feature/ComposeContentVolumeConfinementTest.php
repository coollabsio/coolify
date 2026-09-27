<?php

use App\Jobs\ServerStorageSaveJob;
use App\Livewire\Project\Application\General;
use App\Livewire\Project\Service\StackForm;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const CONTENT_CONFINEMENT_SAFE_COMPOSE = "services:\n  app:\n    image: nginx:alpine\n";

const CONTENT_CONFINEMENT_OUTSIDE_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - type: bind
        source: /root/.ssh/authorized_keys
        target: /keys
        content: ssh-ed25519 AAAA attacker
YAML;

const CONTENT_CONFINEMENT_RELATIVE_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - type: bind
        source: ./config/app.conf
        target: /etc/app.conf
        content: |
          listen 8080;
YAML;

beforeEach(function () {
    Bus::fake();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    config([
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

function contentConfinementService(string $compose): Service
{
    return Service::factory()->create([
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'docker_compose_raw' => $compose,
    ]);
}

function contentConfinementApplication(string $compose): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'git_repository' => 'https://github.com/coollabsio/compose-app',
        'git_branch' => 'main',
        'base_directory' => '/',
        'docker_compose_location' => '/docker-compose.yml',
        'docker_compose_raw' => $compose,
    ]);
}

/**
 * A file volume row that skipped the Compose validation, for example because it was stored before
 * the validation existed or because the content came from the UI.
 */
function contentConfinementFileVolume(Service $service, string $fsPath, ?string $content, bool $isDirectory = false): LocalFileVolume
{
    $serviceApplication = ServiceApplication::create(['name' => 'app', 'service_id' => $service->id]);

    // Bus::fake() keeps the ServerStorageSaveJob of the `created` hook from running.
    return LocalFileVolume::create([
        'fs_path' => $fsPath,
        'mount_path' => '/keys',
        'content' => $content,
        'is_directory' => $isDirectory,
        'resource_id' => $serviceApplication->id,
        'resource_type' => $serviceApplication->getMorphClass(),
    ])->fresh();
}

/**
 * Fakes the server. The symlink check prints $confinement, `test -f`/`test -d` print NOK.
 */
function contentConfinementFakeServer(string $confinement = 'OK'): void
{
    Process::fake(fn ($process) => Process::result(output: match (true) {
        str_contains($process->command, 'readlink -f') => $confinement,
        str_contains($process->command, 'test -') => 'NOK',
        default => '',
    }));
}

function contentConfinementAssertNoContentWrite(): void
{
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'base64 -d'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'touch '));
}

test('the service parser rejects a content volume outside the service directory', function () {
    $service = contentConfinementService(CONTENT_CONFINEMENT_OUTSIDE_COMPOSE);

    expect(fn () => serviceParser($service))
        ->toThrow(Exception::class, 'Volume source /root/.ssh/authorized_keys with content must be inside the resource directory.');

    expect(LocalFileVolume::query()->count())->toBe(0);
    Bus::assertNotDispatched(ServerStorageSaveJob::class);
});

test('the application parser rejects a content volume outside the application directory', function () {
    $application = contentConfinementApplication(CONTENT_CONFINEMENT_OUTSIDE_COMPOSE);

    expect(fn () => applicationParser($application))
        ->toThrow(Exception::class, 'Volume source /root/.ssh/authorized_keys with content must be inside the resource directory.');

    expect(LocalFileVolume::query()->count())->toBe(0);
    Bus::assertNotDispatched(ServerStorageSaveJob::class);
});

test('the parsers reject traversal, home and variable sources with content', function (string $source) {
    $compose = str_replace('/root/.ssh/authorized_keys', $source, CONTENT_CONFINEMENT_OUTSIDE_COMPOSE);

    expect(fn () => serviceParser(contentConfinementService($compose)))
        ->toThrow(Exception::class, 'with content must be inside the resource directory.')
        ->and(fn () => applicationParser(contentConfinementApplication($compose)))
        ->toThrow(Exception::class, 'with content must be inside the resource directory.');

    expect(LocalFileVolume::query()->count())->toBe(0);
})->with([
    './../../../root/.ssh/authorized_keys',
    '../authorized_keys',
    '~/.ssh/authorized_keys',
    '${HOME}/.ssh/authorized_keys',
]);

test('a relative content volume is stored and written inside the service directory', function () {
    $service = contentConfinementService(CONTENT_CONFINEMENT_RELATIVE_COMPOSE);
    serviceParser($service);

    $fileVolume = LocalFileVolume::query()->sole();
    $expectedPath = $service->workdir().'/config/app.conf';
    expect($fileVolume->fs_path)->toBe($expectedPath)
        ->and($fileVolume->content)->toBe('listen 8080;')
        ->and($fileVolume->is_directory)->toBeFalse();

    contentConfinementFakeServer();
    $fileVolume->saveStorageOnServer();

    Process::assertRan(fn ($process) => str_contains($process->command, 'readlink -f')
        && str_contains($process->command, "'{$service->workdir()}' '{$expectedPath}'"));
    Process::assertRan(fn ($process) => str_contains($process->command, "mkdir -p '{$service->workdir()}/config'"));
    Process::assertRan(fn ($process) => str_contains($process->command, "| base64 -d | tee '{$expectedPath}' > /dev/null"));
});

test('a relative content volume is stored inside the application directory', function () {
    $application = contentConfinementApplication(CONTENT_CONFINEMENT_RELATIVE_COMPOSE);
    applicationParser($application);

    expect(LocalFileVolume::query()->sole()->fs_path)->toBe($application->workdir().'/config/app.conf');
});

test('the write path refuses content outside the resource directory for a Compose bind mount', function () {
    // The Compose file no longer has `content:` (the parser removes it), so the mount is administrator-controlled.
    $compose = "services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - type: bind\n        source: /root/.ssh/authorized_keys\n        target: /keys\n";
    $service = contentConfinementService($compose);
    $fileVolume = contentConfinementFileVolume($service, '/root/.ssh/authorized_keys', 'ssh-ed25519 AAAA attacker');

    contentConfinementFakeServer();

    expect(fn () => $fileVolume->saveStorageOnServer())
        ->toThrow(RuntimeException::class, 'Coolify writes file content only inside the resource directory.');
    contentConfinementAssertNoContentWrite();
});

test('the write path refuses ../ traversal out of the resource directory', function () {
    $source = './../../../root/.ssh/authorized_keys';
    $compose = "services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - type: bind\n        source: {$source}\n        target: /keys\n";
    $service = contentConfinementService($compose);
    $fileVolume = contentConfinementFileVolume($service, $service->workdir().'/../../../root/.ssh/authorized_keys', 'attacker');

    contentConfinementFakeServer();

    expect(fn () => $fileVolume->saveStorageOnServer())
        ->toThrow(RuntimeException::class, 'Coolify writes file content only inside the resource directory.');
    contentConfinementAssertNoContentWrite();
});

test('the write path refuses a symlink that leaves the resource directory', function () {
    $service = contentConfinementService(CONTENT_CONFINEMENT_RELATIVE_COMPOSE);
    $fileVolume = contentConfinementFileVolume($service, $service->workdir().'/config/app.conf', 'listen 8080;');

    // The server resolves the symlinked config directory to a path outside the resource directory.
    contentConfinementFakeServer('NOK');

    expect(fn () => $fileVolume->saveStorageOnServer())
        ->toThrow(RuntimeException::class, 'Coolify writes file content only inside the resource directory.');
    contentConfinementAssertNoContentWrite();
});

test('the write path refuses to write content to the resource directory itself', function () {
    $service = contentConfinementService(CONTENT_CONFINEMENT_SAFE_COMPOSE);
    $fileVolume = contentConfinementFileVolume($service, $service->workdir(), 'content');

    contentConfinementFakeServer();

    expect(fn () => $fileVolume->saveStorageOnServer())
        ->toThrow(RuntimeException::class, 'Coolify writes file content only inside the resource directory.');
    contentConfinementAssertNoContentWrite();
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'rm -fr'));
});

test('a bind mount without content keeps its administrator-selected host path', function () {
    $compose = "services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - /srv/shared:/keys\n";
    $service = contentConfinementService($compose);
    $fileVolume = contentConfinementFileVolume($service, '/srv/shared', null, isDirectory: true);

    contentConfinementFakeServer();
    $fileVolume->saveStorageOnServer();

    Process::assertRan(fn ($process) => str_contains($process->command, "mkdir -p '/srv/shared'"));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'readlink -f'));
});

test('a host file bind mount without content is still touched in place', function () {
    $compose = "services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - /etc/localtime:/keys:ro\n";
    $service = contentConfinementService($compose);
    $fileVolume = contentConfinementFileVolume($service, '/etc/localtime', null);

    contentConfinementFakeServer();
    $fileVolume->saveStorageOnServer();

    Process::assertRan(fn ($process) => str_contains($process->command, "touch '/etc/localtime'"));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'readlink -f'));
});

test('loading files for a service does not write content outside the resource directory', function () {
    $compose = "services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - type: bind\n        source: /etc/cron.d/coolify\n        target: /keys\n";
    $service = contentConfinementService($compose);
    $fileVolume = contentConfinementFileVolume($service, '/etc/cron.d/coolify', '* * * * * root id');

    contentConfinementFakeServer();

    expect(fn () => getFilesystemVolumesFromServer($fileVolume->resource, true))
        ->toThrow(Exception::class, 'Coolify writes file content only inside the resource directory.');
    contentConfinementAssertNoContentWrite();
});

test('loading files for a service writes relative content inside the resource directory', function () {
    $service = contentConfinementService(CONTENT_CONFINEMENT_RELATIVE_COMPOSE);
    $expectedPath = $service->workdir().'/config/app.conf';
    $fileVolume = contentConfinementFileVolume($service, $expectedPath, 'listen 8080;');

    contentConfinementFakeServer();
    getFilesystemVolumesFromServer($fileVolume->resource, true);

    Process::assertRan(fn ($process) => str_contains($process->command, "base64 -d | tee -- '{$expectedPath}'"));
});

test('the service stack form rejects a content volume outside the service directory', function () {
    $this->actingAs($this->user);
    $service = contentConfinementService(CONTENT_CONFINEMENT_SAFE_COMPOSE);

    Livewire::test(StackForm::class, ['service' => $service])
        ->set('dockerComposeRaw', CONTENT_CONFINEMENT_OUTSIDE_COMPOSE)
        ->call('submit')
        ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[0], 'with content must be inside the resource directory'));

    expect($service->fresh()->docker_compose_raw)->toBe(CONTENT_CONFINEMENT_SAFE_COMPOSE)
        ->and(LocalFileVolume::query()->count())->toBe(0);
});

test('the application General form rejects a content volume outside the application directory', function () {
    $this->actingAs($this->user);
    $application = contentConfinementApplication(CONTENT_CONFINEMENT_SAFE_COMPOSE);
    Process::fake();

    Livewire::test(General::class, ['application' => $application->fresh()])
        ->set('dockerComposeRaw', CONTENT_CONFINEMENT_OUTSIDE_COMPOSE)
        ->call('submit')
        ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[0], 'with content must be inside the resource directory'))
        ->assertNotDispatched('success');

    expect($application->fresh()->docker_compose_raw)->toBe(CONTENT_CONFINEMENT_SAFE_COMPOSE)
        ->and(LocalFileVolume::query()->count())->toBe(0);
});

test('loading a Git Compose file rejects a content volume outside the application directory', function () {
    $application = contentConfinementApplication(CONTENT_CONFINEMENT_SAFE_COMPOSE);
    Process::fake(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return Process::result(output: match (true) {
            str_contains($command, 'git --version') => 'git version 2.43.0',
            str_contains($command, 'head -c') => CONTENT_CONFINEMENT_OUTSIDE_COMPOSE,
            default => '',
        });
    });

    expect(fn () => $application->loadComposeFile())
        ->toThrow(Exception::class, 'with content must be inside the resource directory');

    expect($application->fresh()->docker_compose_raw)->toBe(CONTENT_CONFINEMENT_SAFE_COMPOSE)
        ->and(LocalFileVolume::query()->count())->toBe(0);
});

test('the service API rejects a content volume outside the service directory', function () {
    $service = contentConfinementService(CONTENT_CONFINEMENT_SAFE_COMPOSE);
    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'content-confinement',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken])
        ->patchJson("/api/v1/services/{$service->uuid}", [
            'docker_compose_raw' => base64_encode(CONTENT_CONFINEMENT_OUTSIDE_COMPOSE),
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.docker_compose_raw', 'Volume source /root/.ssh/authorized_keys with content must be inside the resource directory. Use a relative path such as ./config/app.conf.');

    expect($service->fresh()->docker_compose_raw)->toBe(CONTENT_CONFINEMENT_SAFE_COMPOSE)
        ->and(LocalFileVolume::query()->count())->toBe(0);
});
