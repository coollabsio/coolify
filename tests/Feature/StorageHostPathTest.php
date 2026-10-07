<?php

use App\Jobs\ServerStorageSaveJob;
use App\Livewire\Project\Service\FileStorage;
use App\Livewire\Project\Service\Storage;
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
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

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
    ]);

    storageHostPathFakeServer();
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
});

/**
 * `test -L` prints $symlink and `test -f`/`test -d` print $exists.
 */
function storageHostPathFakeServer(string $symlink = 'OK', string $exists = 'NOK'): void
{
    Process::fake(fn ($process) => Process::result(output: match (true) {
        str_contains($process->command, 'test -L') => $symlink,
        str_contains($process->command, 'readlink -f') => 'OK',
        str_contains($process->command, 'test -') => $exists,
        default => '',
    }));
}

/**
 * @param  list<string>  $abilities
 */
function storageHostPathToken(User $user, array $abilities = ['*']): string
{
    // A token request must not use the session user of the Livewire tests.
    auth()->forgetGuards();
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'host-path-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => test()->team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

/**
 * @return array{0: string, 1: array<string, string>, 2: mixed}
 */
function storageHostPathApiTarget(string $resourceType): array
{
    if ($resourceType === 'application') {
        return ['/api/v1/applications/'.test()->application->uuid.'/storages', [], test()->application];
    }
    if ($resourceType === 'database') {
        $database = StandalonePostgresql::create([
            'name' => 'host-path-postgres',
            'image' => 'postgres:15-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
            'environment_id' => test()->environment->id,
            'destination_id' => test()->destination->id,
            'destination_type' => test()->destination->getMorphClass(),
        ]);

        return ["/api/v1/databases/{$database->uuid}/storages", [], $database];
    }

    $service = Service::factory()->create([
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n",
    ]);
    $serviceApplication = ServiceApplication::create(['name' => 'app', 'service_id' => $service->id]);

    return ["/api/v1/services/{$service->uuid}/storages", ['resource_uuid' => $serviceApplication->uuid], $serviceApplication];
}

function storageHostPathFileVolume(string $fsPath, ?string $content, bool $isDirectory = false): LocalFileVolume
{
    return LocalFileVolume::create([
        'fs_path' => $fsPath,
        'mount_path' => '/mnt/'.md5($fsPath),
        'content' => $content,
        'is_directory' => $isDirectory,
        'resource_id' => test()->application->id,
        'resource_type' => test()->application->getMorphClass(),
    ])->fresh();
}

function storageHostPathAssertNoDelete(): void
{
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'rm -') || str_contains($process->command, 'rmdir'));
}

test('an admin creates a directory mount anywhere on the host in the UI', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_directory_source', '/srv/zz')
        ->set('file_storage_directory_destination', '/data')
        ->call('submitFileStorageDirectory')
        ->assertDispatched('success');

    $volume = LocalFileVolume::query()->sole();
    expect($volume->fs_path)->toBe('/srv/zz')
        ->and($volume->is_directory)->toBeTrue();

    $volume->saveStorageOnServer();
    Process::assertRan(fn ($process) => str_contains($process->command, "mkdir -p '/srv/zz'"));
});

test('an admin creates a file mount with content anywhere on the host in the UI', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_source', '/etc/zz/app.conf')
        ->set('file_storage_path', '/etc/app.conf')
        ->set('file_storage_content', 'listen 8080;')
        ->call('submitFileStorage')
        ->assertDispatched('success');

    $volume = LocalFileVolume::query()->sole();
    expect($volume->fs_path)->toBe('/etc/zz/app.conf')
        ->and($volume->mount_path)->toBe('/etc/app.conf');

    $volume->saveStorageOnServer();
    Process::assertRan(fn ($process) => str_contains($process->command, "mkdir -p '/etc/zz'"));
    Process::assertRan(fn ($process) => preg_match("#base64 -d \\| (sudo )?tee '/etc/zz/app.conf' > /dev/null#", $process->command) === 1);
    expect($volume->contentPathOnServer())->toBe('/etc/zz/app.conf');
});

test('an admin creates directory and file mounts anywhere on the host through the API', function (string $resourceType) {
    [$url, $payload, $resource] = storageHostPathApiTarget($resourceType);
    $token = storageHostPathToken($this->admin);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson($url, [...$payload, ...[
        'type' => 'file',
        'is_directory' => true,
        'fs_path' => '/srv/zz',
        'mount_path' => '/data',
    ]])->assertCreated()->assertJsonPath('fs_path', '/srv/zz');

    $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson($url, [...$payload, ...[
        'type' => 'file',
        'fs_path' => '/etc/zz/app.conf',
        'mount_path' => '/etc/app.conf',
        'content' => 'listen 8080;',
    ]])->assertCreated()->assertJsonPath('fs_path', '/etc/zz/app.conf');

    expect($resource->fileStorages()->orderBy('id')->pluck('fs_path')->all())->toBe(['/srv/zz', '/etc/zz/app.conf']);
})->with(['application', 'database', 'service']);

test('members cannot create host path mounts', function () {
    $this->actingAs($this->member);

    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_directory_source', '/srv/zz')
        ->set('file_storage_directory_destination', '/data')
        ->call('submitFileStorageDirectory')
        ->assertDispatched('error');

    $this->withHeaders(['Authorization' => 'Bearer '.storageHostPathToken($this->member)])
        ->postJson("/api/v1/applications/{$this->application->uuid}/storages", [
            'type' => 'file',
            'fs_path' => '/etc/zz/app.conf',
            'mount_path' => '/etc/app.conf',
            'content' => 'listen 8080;',
        ])->assertForbidden();

    expect(LocalFileVolume::query()->count())->toBe(0);
});

test('host paths with traversal, home or shell characters are rejected', function (string $path) {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_directory_source', $path)
        ->set('file_storage_directory_destination', '/data')
        ->call('submitFileStorageDirectory')
        ->assertDispatched('error');

    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_source', $path)
        ->set('file_storage_path', '/etc/app.conf')
        ->set('file_storage_content', 'listen 8080;')
        ->call('submitFileStorage')
        ->assertDispatched('error');

    $this->withHeaders(['Authorization' => 'Bearer '.storageHostPathToken($this->admin)])
        ->postJson("/api/v1/applications/{$this->application->uuid}/storages", [
            'type' => 'file',
            'is_directory' => true,
            'fs_path' => $path,
            'mount_path' => '/data',
        ])->assertUnprocessable();

    expect(LocalFileVolume::query()->count())->toBe(0);
})->with([
    'parent segment' => '/srv/../etc/zz',
    'relative escape' => '../../../etc/zz',
    'home directory' => '~/zz',
    'command separator' => '/srv/zz;id',
    'command substitution' => '/srv/$(id)',
]);

test('a symbolic link at a file path outside the resource directory is refused', function () {
    storageHostPathFakeServer(symlink: 'LINK');
    $volume = storageHostPathFileVolume('/etc/zz/app.conf', 'listen 8080;');

    expect(fn () => $volume->saveStorageOnServer())
        ->toThrow(RuntimeException::class, '/etc/zz/app.conf is a symbolic link on the server.');
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'base64 -d'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'touch '));

    $this->withHeaders(['Authorization' => 'Bearer '.storageHostPathToken($this->admin)])
        ->postJson("/api/v1/applications/{$this->application->uuid}/storages", [
            'type' => 'file',
            'fs_path' => '/etc/zz/other.conf',
            'mount_path' => '/etc/other.conf',
            'content' => 'listen 8080;',
        ])->assertUnprocessable();

    expect(LocalFileVolume::query()->count())->toBe(1);
});

test('deleting a mount outside the resource directory never deletes on the host', function () {
    storageHostPathFakeServer(exists: 'OK');
    $directory = storageHostPathFileVolume('/srv/zz', null, isDirectory: true);
    $file = storageHostPathFileVolume('/etc/zz/app.conf', 'listen 8080;');

    $directory->deleteStorageOnServer();
    $file->deleteStorageOnServer();
    storageHostPathAssertNoDelete();

    Livewire::test(FileStorage::class, ['fileStorage' => $file->fresh()])
        ->set('permanently_delete', true)
        ->call('delete', 'password')
        ->assertDispatched('success', 'File deleted.');

    storageHostPathAssertNoDelete();
    expect(LocalFileVolume::query()->pluck('fs_path')->all())->toBe(['/srv/zz']);
});

test('deleting a mount inside the resource directory still deletes it on the host', function () {
    storageHostPathFakeServer(exists: 'OK');
    $path = $this->application->workdir().'/data/zz';

    storageHostPathFileVolume($path, null, isDirectory: true)->deleteStorageOnServer();

    Process::assertRan(fn ($process) => str_contains($process->command, "rm -rf '{$path}'"));
});

test('the UI deletes a mount inside the resource directory on the host only when the user keeps the option checked', function (bool $isDirectory, array $selectedActions, bool $deletesOnHost, string $message) {
    storageHostPathFakeServer(exists: 'OK');
    $path = $this->application->workdir().'/data/zz';
    $volume = storageHostPathFileVolume($path, $isDirectory ? null : 'listen 8080;', isDirectory: $isDirectory);

    Livewire::test(FileStorage::class, ['fileStorage' => $volume])
        ->call('delete', 'password', $selectedActions)
        ->assertDispatched('success', $message);

    expect($volume->fresh())->toBeNull();
    if ($deletesOnHost) {
        Process::assertRan(fn ($process) => str_contains($process->command, "rm -rf '{$path}'"));
    } else {
        storageHostPathAssertNoDelete();
    }
})->with([
    'directory, option checked' => [true, ['permanently_delete'], true, 'Directory deleted from the server.'],
    'directory, option unchecked' => [true, [], false, 'Directory deleted.'],
    'file, option checked' => [false, ['permanently_delete'], true, 'File deleted from the server.'],
    'file, option unchecked' => [false, [], false, 'File deleted.'],
]);

test('the delete dialog offers deletion on the host only for mounts inside the resource directory', function () {
    $inside = storageHostPathFileVolume($this->application->workdir().'/data/zz', 'listen 8080;');
    $outside = storageHostPathFileVolume('/etc/zz/app.conf', 'listen 8080;');
    $outsideDirectory = storageHostPathFileVolume('/srv/zz', null, isDirectory: true);

    $insideDialog = Livewire::test(FileStorage::class, ['fileStorage' => $inside]);
    expect($insideDialog->viewData('deletionCheckboxes'))->toHaveCount(1)
        ->and($insideDialog->viewData('deletionCheckboxes')[0]['id'])->toBe('permanently_delete');

    foreach ([$outside, $outsideDirectory] as $volume) {
        $dialog = Livewire::test(FileStorage::class, ['fileStorage' => $volume]);
        expect($dialog->viewData('deletionCheckboxes'))->toBe([])
            ->and(implode(' ', $dialog->viewData('deletionActions')))->toContain('is not deleted on the server');
    }
});

test('a file outside the resource directory never replaces a directory on the host', function () {
    Process::fake(fn ($process) => Process::result(output: match (true) {
        str_contains($process->command, 'test -L') => 'OK',
        str_contains($process->command, 'test -d') => 'OK',
        str_contains($process->command, 'test -f') => 'NOK',
        str_contains($process->command, 'sh -c') => '1:empty-directory',
        default => '',
    }));

    expect(fn () => storageHostPathFileVolume('/etc/zz/app.conf', 'listen 8080;')->saveStorageOnServer())
        ->toThrow(Exception::class, 'is a directory on the server');
    storageHostPathAssertNoDelete();
});

test('relative host paths stay inside the resource directory', function () {
    $workdir = $this->application->workdir();

    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_directory_source', 'data/zz')
        ->set('file_storage_directory_destination', '/data')
        ->call('submitFileStorageDirectory')
        ->assertDispatched('success');

    $this->withHeaders(['Authorization' => 'Bearer '.storageHostPathToken($this->admin)])
        ->postJson("/api/v1/applications/{$this->application->uuid}/storages", [
            'type' => 'file',
            'fs_path' => './config/app.conf',
            'mount_path' => '/etc/app.conf',
            'content' => 'listen 8080;',
        ])->assertCreated();

    expect(LocalFileVolume::query()->orderBy('id')->pluck('fs_path')->all())
        ->toBe([$workdir.'/data/zz', $workdir.'/config/app.conf']);
    Process::assertRan(fn ($process) => str_contains($process->command, 'readlink -f')
        && str_contains($process->command, "'{$workdir}' '{$workdir}/data/zz'"));
});

test('a token without deploy cannot create a mount outside the resource directory', function (string $resourceType, array $mount) {
    [$url, $payload] = storageHostPathApiTarget($resourceType);

    $this->withHeaders(['Authorization' => 'Bearer '.storageHostPathToken($this->admin, ['read', 'write'])])
        ->postJson($url, [...$payload, 'type' => 'file', ...$mount])
        ->assertForbidden()
        ->assertJsonPath('message', 'Missing required permissions: deploy. A mount outside the resource directory needs a token with the deploy permission.');

    expect(LocalFileVolume::query()->count())->toBe(0);
    Bus::assertNotDispatched(ServerStorageSaveJob::class);
    Process::assertNothingRan();
})->with(['application', 'database', 'service'])->with([
    'directory' => [['is_directory' => true, 'fs_path' => '/root/.ssh', 'mount_path' => '/data']],
    'file with content' => [['fs_path' => '/root/.ssh/authorized_keys', 'mount_path' => '/etc/keys', 'content' => 'ssh-ed25519 AAAA']],
    'empty file' => [['fs_path' => '/etc/zz/app.conf', 'mount_path' => '/etc/app.conf']],
]);

test('a deploy or root token creates a mount outside the resource directory', function (string $resourceType, array $abilities) {
    [$url, $payload, $resource] = storageHostPathApiTarget($resourceType);
    $token = storageHostPathToken($this->admin, $abilities);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson($url, [...$payload, 'type' => 'file', 'is_directory' => true, 'fs_path' => '/srv/zz', 'mount_path' => '/data'])
        ->assertCreated();
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson($url, [...$payload, 'type' => 'file', 'fs_path' => '/etc/zz/app.conf', 'mount_path' => '/etc/app.conf', 'content' => 'listen 8080;'])
        ->assertCreated();

    expect($resource->fileStorages()->orderBy('id')->pluck('fs_path')->all())->toBe(['/srv/zz', '/etc/zz/app.conf']);
})->with(['application', 'database', 'service'])->with([
    'deploy' => [['read', 'write', 'deploy']],
    'root' => [['root']],
]);

test('a write token creates mounts inside the resource directory', function (string $resourceType) {
    [$url, $payload, $resource] = storageHostPathApiTarget($resourceType);
    $token = storageHostPathToken($this->admin, ['read', 'write']);
    $workdir = $resource instanceof ServiceApplication ? $resource->service->workdir() : $resource->workdir();

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson($url, [...$payload, 'type' => 'file', 'is_directory' => true, 'fs_path' => 'data/zz', 'mount_path' => '/data'])
        ->assertCreated();
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson($url, [...$payload, 'type' => 'file', 'fs_path' => $workdir.'/config/app.conf', 'mount_path' => '/etc/app.conf', 'content' => 'listen 8080;'])
        ->assertCreated();
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson($url, [...$payload, 'type' => 'file', 'mount_path' => '/etc/other.conf', 'content' => 'listen 8081;'])
        ->assertCreated();
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson($url, [...$payload, 'type' => 'file', 'is_host_file' => true, 'fs_path' => '/etc/hosts', 'mount_path' => '/etc/hosts'])
        ->assertCreated();

    expect($resource->fileStorages()->count())->toBe(4);
})->with(['application', 'database', 'service']);

test('changing the content of a mount outside the resource directory needs deploy', function (string $resourceType) {
    [$url, , $resource] = storageHostPathApiTarget($resourceType);
    $storage = LocalFileVolume::create([
        'fs_path' => '/etc/zz/app.conf',
        'mount_path' => '/etc/app.conf',
        'content' => 'listen 8080;',
        'resource_id' => $resource->id,
        'resource_type' => $resource->getMorphClass(),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.storageHostPathToken($this->admin, ['read', 'write'])])
        ->patchJson($url, ['type' => 'file', 'uuid' => $storage->uuid, 'content' => 'ssh-ed25519 AAAA'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Missing required permissions: deploy. A mount outside the resource directory needs a token with the deploy permission.');
    expect($storage->fresh()->content)->toBe('listen 8080;');

    $this->withHeaders(['Authorization' => 'Bearer '.storageHostPathToken($this->admin, ['read', 'write'])])
        ->patchJson($url, ['type' => 'file', 'uuid' => $storage->uuid, 'mount_path' => '/etc/app2.conf', 'content' => 'listen 8080;'])
        ->assertOk();
    expect($storage->fresh()->mount_path)->toBe('/etc/app2.conf');

    $this->withHeaders(['Authorization' => 'Bearer '.storageHostPathToken($this->admin, ['read', 'write', 'deploy'])])
        ->patchJson($url, ['type' => 'file', 'uuid' => $storage->uuid, 'content' => 'listen 9090;'])
        ->assertOk();
    expect($storage->fresh()->content)->toBe('listen 9090;');
})->with(['application', 'database', 'service']);
