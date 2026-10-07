<?php

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
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * Only administrators can edit a Compose file, and they can mount any host path. Coolify writes the
 * `content:` of a Compose bind volume to the source path, like Coolify v4.3.23 did, also outside
 * the resource directory. Every path in a remote command is shell-escaped, and the source must not
 * contain shell metacharacters.
 */
uses(RefreshDatabase::class);

const CONTENT_HOST_PATH_SAFE_COMPOSE = "services:\n  app:\n    image: nginx:alpine\n";

const CONTENT_HOST_PATH_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - type: bind
        source: /etc/myapp/app.conf
        target: /etc/a.conf
        content: a
      - type: bind
        source: ~/x/app.conf
        target: /etc/b.conf
        content: b
      - type: bind
        source: app.conf
        target: /etc/c.conf
        content: c
      - type: bind
        source: ./../shared/app.conf
        target: /etc/d.conf
        content: d
YAML;

const CONTENT_HOST_PATH_INJECTION_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - type: bind
        source: /etc/$(id)/app.conf
        target: /etc/app.conf
        content: x
YAML;

beforeEach(function () {
    Server::flushIdentityMap();
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

afterEach(function () {
    Server::flushIdentityMap();
});

function contentHostPathService(string $compose): Service
{
    return Service::factory()->create([
        'name' => 'content-host-path',
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'docker_compose_raw' => $compose,
        'connect_to_docker_network' => false,
    ]);
}

function contentHostPathApplication(string $compose): Application
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

function contentHostPathUseServerUser(string $user): void
{
    test()->server->update(['user' => $user]);
    Server::flushIdentityMap();
}

/**
 * Fakes the server: `test -f`/`test -d` print NOK (the file is missing), the symlink check prints OK.
 */
function contentHostPathFakeServer(): void
{
    Process::fake(fn ($process) => Process::result(output: match (true) {
        str_contains($process->command, 'readlink -f') => 'OK',
        str_contains($process->command, 'test -') => 'NOK',
        default => '',
    }));
}

/**
 * The stored fs_path and the host path that Coolify writes, for a source in CONTENT_HOST_PATH_COMPOSE.
 * `~` and `./` are relative to the resource directory, as in v4.3.23. A bare relative path is
 * stored as written and written inside the resource directory (never relative to the SSH user's
 * working directory).
 *
 * @return array{0: string, 1: string}
 */
function contentHostPathExpected(string $target, string $workdir): array
{
    $parent = dirname($workdir);

    return match ($target) {
        '/etc/a.conf' => ['/etc/myapp/app.conf', '/etc/myapp/app.conf'],
        '/etc/b.conf' => [$workdir.'/x/app.conf', $workdir.'/x/app.conf'],
        '/etc/c.conf' => ['app.conf', $workdir.'/app.conf'],
        '/etc/d.conf' => [$parent.'/shared/app.conf', $parent.'/shared/app.conf'],
    };
}

function contentHostPathAssertWritten(string $path, string $content, string $user): void
{
    $sudo = $user === 'root' ? '' : 'sudo ';
    $escapedPath = escapeshellarg($path);
    $escapedParent = escapeshellarg(dirname($path));
    $base64 = base64_encode($content);

    Process::assertRan(fn ($process) => str_contains($process->command, "{$sudo}mkdir -p {$escapedParent}"));
    Process::assertRan(fn ($process) => str_contains($process->command, "echo '{$base64}' | {$sudo}base64 -d | {$sudo}tee {$escapedPath} > /dev/null"));
}

/**
 * @return array<string, array{string, string}>
 */
function contentHostPathTargets(): array
{
    return [
        'absolute host file' => ['/etc/a.conf', 'a'],
        'home directory' => ['/etc/b.conf', 'b'],
        'bare relative path' => ['/etc/c.conf', 'c'],
        'parent directory' => ['/etc/d.conf', 'd'],
    ];
}

test('the service parser stores a content volume at its host path and Coolify writes it there', function (string $target, string $content, string $user) {
    contentHostPathUseServerUser($user);
    $service = contentHostPathService(CONTENT_HOST_PATH_COMPOSE);
    serviceParser($service);

    [$fsPath, $writePath] = contentHostPathExpected($target, $service->workdir());
    $fileVolume = LocalFileVolume::query()->where('mount_path', $target)->sole();
    expect($fileVolume->fs_path)->toBe($fsPath)
        ->and($fileVolume->content)->toBe($content)
        ->and($fileVolume->is_directory)->toBeFalse()
        ->and($fileVolume->contentPathOnServer())->toBe($writePath);

    contentHostPathFakeServer();
    $fileVolume->saveStorageOnServer();

    contentHostPathAssertWritten($writePath, $content, $user);
})->with(contentHostPathTargets())->with(['root', 'coolify']);

test('the application parser stores a content volume at its host path and Coolify writes it there', function (string $target, string $content, string $user) {
    contentHostPathUseServerUser($user);
    $application = contentHostPathApplication(CONTENT_HOST_PATH_COMPOSE);
    applicationParser($application);

    [$fsPath, $writePath] = contentHostPathExpected($target, $application->workdir());
    $fileVolume = LocalFileVolume::query()->where('mount_path', $target)->sole();
    expect($fileVolume->fs_path)->toBe($fsPath)
        ->and($fileVolume->content)->toBe($content)
        ->and($fileVolume->contentPathOnServer())->toBe($writePath);

    contentHostPathFakeServer();
    $fileVolume->saveStorageOnServer();

    contentHostPathAssertWritten($writePath, $content, $user);
})->with(contentHostPathTargets())->with(['root', 'coolify']);

test('loading files for a service writes content at its host path', function (string $target, string $content, string $user) {
    contentHostPathUseServerUser($user);
    $service = contentHostPathService(CONTENT_HOST_PATH_COMPOSE);
    serviceParser($service);
    $fileVolume = LocalFileVolume::query()->where('mount_path', $target)->sole();
    [, $writePath] = contentHostPathExpected($target, $service->workdir());

    contentHostPathFakeServer();
    getFilesystemVolumesFromServer($fileVolume->resource, true);

    $sudo = $user === 'root' ? '' : 'sudo ';
    $escapedPath = escapeshellarg($writePath);
    $base64 = base64_encode($content);
    Process::assertRan(fn ($process) => str_contains($process->command, "{$sudo}mkdir -p -- \"$({$sudo}dirname -- {$escapedPath})\""));
    Process::assertRan(fn ($process) => str_contains($process->command, "echo '{$base64}' | {$sudo}base64 -d | {$sudo}tee -- {$escapedPath}"));
})->with(contentHostPathTargets())->with(['root', 'coolify']);

test('a quote in a content path is shell-escaped in every write command', function (string $user) {
    contentHostPathUseServerUser($user);
    $path = "/etc/my'app/app.conf";
    $compose = "services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - type: bind\n        source: \"{$path}\"\n        target: /etc/app.conf\n        content: x\n";
    $service = contentHostPathService($compose);
    serviceParser($service);
    $fileVolume = LocalFileVolume::query()->sole();
    expect($fileVolume->fs_path)->toBe($path);

    contentHostPathFakeServer();
    $fileVolume->saveStorageOnServer();
    getFilesystemVolumesFromServer($fileVolume->resource, true);

    $sudo = $user === 'root' ? '' : 'sudo ';
    Process::assertRan(fn ($process) => str_contains($process->command, "{$sudo}tee '/etc/my'\\''app/app.conf' > /dev/null"));
    Process::assertRan(fn ($process) => str_contains($process->command, "{$sudo}tee -- '/etc/my'\\''app/app.conf'"));
    Process::assertRan(fn ($process) => str_contains($process->command, "{$sudo}mkdir -p '/etc/my'\\''app'"));
})->with(['root', 'coolify']);

test('the parsers reject shell injection in a content volume source', function () {
    expect(fn () => serviceParser(contentHostPathService(CONTENT_HOST_PATH_INJECTION_COMPOSE)))
        ->toThrow(Exception::class, 'Invalid Docker volume definition (array syntax)')
        ->and(fn () => applicationParser(contentHostPathApplication(CONTENT_HOST_PATH_INJECTION_COMPOSE)))
        ->toThrow(Exception::class, 'Invalid Docker volume definition (array syntax)');

    expect(LocalFileVolume::query()->count())->toBe(0);
});

test('a bind mount without content keeps its administrator-selected host path', function () {
    $compose = "services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - /srv/shared:/keys\n";
    $service = contentHostPathService($compose);
    $serviceApplication = ServiceApplication::create(['name' => 'app', 'service_id' => $service->id]);
    $fileVolume = LocalFileVolume::create([
        'fs_path' => '/srv/shared',
        'mount_path' => '/keys',
        'is_directory' => true,
        'resource_id' => $serviceApplication->id,
        'resource_type' => $serviceApplication->getMorphClass(),
    ])->fresh();

    contentHostPathFakeServer();
    $fileVolume->saveStorageOnServer();

    Process::assertRan(fn ($process) => str_contains($process->command, "mkdir -p '/srv/shared'"));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'readlink -f'));
});

test('the service stack form saves content volumes with host paths', function () {
    $this->actingAs($this->user);
    $service = contentHostPathService(CONTENT_HOST_PATH_SAFE_COMPOSE);
    contentHostPathFakeServer();

    Livewire::test(StackForm::class, ['service' => $service])
        ->set('dockerComposeRaw', CONTENT_HOST_PATH_COMPOSE)
        ->call('submit')
        ->assertNotDispatched('error');

    expect($service->fresh()->docker_compose_raw)->toContain('source: /etc/myapp/app.conf')
        ->and(LocalFileVolume::query()->where('fs_path', '/etc/myapp/app.conf')->value('content'))->toBe('a');
});

test('the service stack form rejects shell injection in a content volume source', function () {
    $this->actingAs($this->user);
    $service = contentHostPathService(CONTENT_HOST_PATH_SAFE_COMPOSE);

    Livewire::test(StackForm::class, ['service' => $service])
        ->set('dockerComposeRaw', CONTENT_HOST_PATH_INJECTION_COMPOSE)
        ->call('submit')
        ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[0], 'Invalid Docker volume definition'));

    expect($service->fresh()->docker_compose_raw)->toBe(CONTENT_HOST_PATH_SAFE_COMPOSE)
        ->and(LocalFileVolume::query()->count())->toBe(0);
});

test('the application General form saves content volumes with host paths', function () {
    $this->actingAs($this->user);
    $application = contentHostPathApplication(CONTENT_HOST_PATH_SAFE_COMPOSE);
    contentHostPathFakeGitCompose(CONTENT_HOST_PATH_COMPOSE);

    Livewire::test(General::class, ['application' => $application->fresh()])
        ->set('dockerComposeRaw', CONTENT_HOST_PATH_COMPOSE)
        ->call('submit')
        ->assertNotDispatched('error');

    expect($application->fresh()->docker_compose_raw)->toContain('source: /etc/myapp/app.conf')
        ->and(LocalFileVolume::query()->where('fs_path', '/etc/myapp/app.conf')->value('content'))->toBe('a');
});

test('the application General form rejects shell injection in a content volume source', function () {
    $this->actingAs($this->user);
    $application = contentHostPathApplication(CONTENT_HOST_PATH_SAFE_COMPOSE);
    Process::fake();

    Livewire::test(General::class, ['application' => $application->fresh()])
        ->set('dockerComposeRaw', CONTENT_HOST_PATH_INJECTION_COMPOSE)
        ->call('submit')
        ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[0], 'Invalid Docker volume definition'))
        ->assertNotDispatched('success');

    expect($application->fresh()->docker_compose_raw)->toBe(CONTENT_HOST_PATH_SAFE_COMPOSE)
        ->and(LocalFileVolume::query()->count())->toBe(0);
});

function contentHostPathFakeGitCompose(string $compose): void
{
    Process::fake(function ($process) use ($compose) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return Process::result(output: match (true) {
            str_contains($command, 'git --version') => 'git version 2.43.0',
            str_contains($command, 'head -c') => $compose,
            default => '',
        });
    });
}

test('loading a Git Compose file accepts content volumes with host paths', function () {
    $application = contentHostPathApplication(CONTENT_HOST_PATH_SAFE_COMPOSE);
    contentHostPathFakeGitCompose(CONTENT_HOST_PATH_COMPOSE);

    $application->loadComposeFile();

    expect($application->fresh()->docker_compose_raw)->toContain('source: /etc/myapp/app.conf')
        ->and(LocalFileVolume::query()->orderBy('mount_path')->pluck('fs_path')->all())->toBe([
            '/etc/myapp/app.conf',
            $application->workdir().'/x/app.conf',
            'app.conf',
            dirname($application->workdir()).'/shared/app.conf',
        ]);
});

test('loading a Git Compose file rejects shell injection in a content volume source', function () {
    $application = contentHostPathApplication(CONTENT_HOST_PATH_SAFE_COMPOSE);
    contentHostPathFakeGitCompose(CONTENT_HOST_PATH_INJECTION_COMPOSE);

    expect(fn () => $application->loadComposeFile())
        ->toThrow(Exception::class, 'Invalid Docker volume definition');

    expect($application->fresh()->docker_compose_raw)->toBe(CONTENT_HOST_PATH_SAFE_COMPOSE)
        ->and(LocalFileVolume::query()->count())->toBe(0);
});

function contentHostPathPatchService(Service $service, string $compose): TestResponse
{
    $plainTextToken = Str::random(40);
    $token = test()->user->tokens()->create([
        'name' => 'content-host-path',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => test()->team->id,
    ]);

    return test()->withHeaders(['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken])
        ->patchJson("/api/v1/services/{$service->uuid}", ['docker_compose_raw' => base64_encode($compose)]);
}

test('the service API saves content volumes with host paths', function () {
    $service = contentHostPathService(CONTENT_HOST_PATH_SAFE_COMPOSE);
    contentHostPathFakeServer();

    contentHostPathPatchService($service, CONTENT_HOST_PATH_COMPOSE)->assertSuccessful();

    expect($service->fresh()->docker_compose_raw)->toContain('source: /etc/myapp/app.conf')
        ->and(LocalFileVolume::query()->where('fs_path', '/etc/myapp/app.conf')->value('content'))->toBe('a');
});

test('the service API rejects shell injection in a content volume source', function () {
    $service = contentHostPathService(CONTENT_HOST_PATH_SAFE_COMPOSE);

    contentHostPathPatchService($service, CONTENT_HOST_PATH_INJECTION_COMPOSE)
        ->assertStatus(422)
        ->assertJsonPath('errors.docker_compose_raw', fn (string $error): bool => str_contains($error, 'Invalid Docker volume definition'));

    expect($service->fresh()->docker_compose_raw)->toBe(CONTENT_HOST_PATH_SAFE_COMPOSE)
        ->and(LocalFileVolume::query()->count())->toBe(0);
});
