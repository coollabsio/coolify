<?php

/**
 * Unit tests for LocalFileVolume content size handling.
 *
 * Related Issue: #4701 - Storages page becomes unusable when Docker volumes
 * mount large host files. Coolify previously stored full file content in the
 * encrypted `content` mediumText column, then serialized it to the Livewire
 * payload, crashing the browser.
 */

use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('exposes a 5 MiB content size limit', function () {
    expect(LocalFileVolume::MAX_CONTENT_SIZE)->toBe(5_242_880);
});

it('exposes binary and too-large placeholder constants', function () {
    expect(LocalFileVolume::BINARY_PLACEHOLDER)->toBe('[binary file]');
    expect(LocalFileVolume::TOO_LARGE_PLACEHOLDER)->toBe('[file too large to display]');
});

it('flags is_too_large when content matches the placeholder', function () {
    $volume = new LocalFileVolume;
    $volume->content = LocalFileVolume::TOO_LARGE_PLACEHOLDER;

    expect($volume->is_too_large)->toBeTrue();
    expect($volume->is_binary)->toBeFalse();
});

it('flags is_binary when content matches the placeholder', function () {
    $volume = new LocalFileVolume;
    $volume->content = LocalFileVolume::BINARY_PLACEHOLDER;

    expect($volume->is_binary)->toBeTrue();
    expect($volume->is_too_large)->toBeFalse();
});

it('does not flag normal content as binary or too large', function () {
    $volume = new LocalFileVolume;
    $volume->content = "hello\nworld\n";

    expect($volume->is_binary)->toBeFalse();
    expect($volume->is_too_large)->toBeFalse();
});

it('does not flag empty content as binary or too large', function () {
    $volume = new LocalFileVolume;
    $volume->content = null;

    expect($volume->is_binary)->toBeFalse();
    expect($volume->is_too_large)->toBeFalse();
});

it('exposes the too-large flag via toArray for Livewire serialization', function () {
    $volume = new LocalFileVolume;
    $volume->content = LocalFileVolume::TOO_LARGE_PLACEHOLDER;

    $array = $volume->toArray();

    expect($array)->toHaveKey('is_too_large');
    expect($array['is_too_large'])->toBeTrue();
});

it('does not read regular bind-mounted file contents while loading service settings', function () {
    $user = User::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $user->teams()->first()->id]);
    Storage::fake('ssh-keys');
    $server = Server::factory()->create([
        'team_id' => $user->teams()->first()->id,
        'private_key_id' => $privateKey->id,
    ]);

    $volume = Mockery::mock(LocalFileVolume::class)->makePartial();
    $volume->fs_path = '/data/large.bin';
    $volume->is_based_on_git = false;
    $volume->shouldReceive('save')->once();
    $volume->shouldNotReceive('loadStorageOnServer');

    $fileStorages = Mockery::mock(MorphMany::class);
    $fileStorages->shouldReceive('get')->once()->andReturn(collect([$volume]));

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('getMorphClass')->andReturn(Application::class);
    $application->shouldReceive('workdir')->once()->andReturn('/data/application');
    $application->shouldReceive('fileStorages')->once()->andReturn($fileStorages);
    $application->setRelation('destination', (object) ['server' => $server]);

    Process::fake(function ($process) {
        if (str_contains($process->command, "test -f '/data/large.bin'")) {
            return Process::result(output: 'OK');
        }

        if (str_contains($process->command, "test -d '/data/large.bin'")) {
            return Process::result(output: 'NOK');
        }

        return Process::result();
    });

    getFilesystemVolumesFromServer($application);

    expect($volume->is_directory)->toBeFalse();
    Process::assertRan(fn ($process) => str_contains($process->command, "test -f '/data/large.bin'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'cat /data/large.bin') || str_contains($process->command, 'head -c'));
});

it('does not interpolate unsafe persisted file-storage paths into remote commands', function (string $path) {
    $user = User::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $user->teams()->first()->id]);
    Storage::fake('ssh-keys');
    $server = Server::factory()->create([
        'team_id' => $user->teams()->first()->id,
        'private_key_id' => $privateKey->id,
    ]);

    $volume = Mockery::mock(LocalFileVolume::class)->makePartial();
    $volume->fs_path = $path;

    $fileStorages = Mockery::mock(MorphMany::class);
    $fileStorages->shouldReceive('get')->once()->andReturn(collect([$volume]));

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('getMorphClass')->andReturn(Application::class);
    $application->shouldReceive('workdir')->once()->andReturn('/data/application');
    $application->shouldReceive('fileStorages')->once()->andReturn($fileStorages);
    $application->setRelation('destination', (object) ['server' => $server]);

    Process::fake();
    expect(fn () => getFilesystemVolumesFromServer($application, true))->toThrow(Exception::class);

    Process::assertNotRan(fn ($process) => str_contains($process->command, $path));
})->with(['/tmp/evil`id`', '/tmp/evil$(id)', '/tmp/evil;id', '/tmp/evil|id', '${DATA:-/tmp/evil$(id)}', '/srv/$HOME;id', '${DATA:-/srv/app;id}/config.yml', '${DATA:-${HOME:-$(id)}}', '${DATA:+/srv/app;id}/config.yml']);

it('preserves safe remote-shell path expansion', function (string $path, array $environment, string $expected) {
    $argument = filesystemVolumeShellArgument($path);
    $process = new SymfonyProcess(['bash', '-c', "printf '%s' {$argument}"], env: $environment);
    $process->mustRun();

    expect($process->getOutput())->toBe($expected);
})->with([
    'bare variable' => ['$HOME/config.yml', ['HOME' => '/tmp/test-home'], '/tmp/test-home/config.yml'],
    'variable within absolute path' => ['/srv/$HOME/config.yml', ['HOME' => 'tenant'], '/srv/tenant/config.yml'],
    'multiple variables' => ['$HOME/$FILE', ['HOME' => '/tmp/test-home', 'FILE' => 'config.yml'], '/tmp/test-home/config.yml'],
    'home shortcut' => ['~/config.yml', ['HOME' => '/tmp/test-home'], '/tmp/test-home/config.yml'],
    'braced variable' => ['${DATA_PATH}/config.yml', ['DATA_PATH' => '/srv/my data'], '/srv/my data/config.yml'],
    'default when unset' => ['${DATA_PATH:-/srv/app/config.yml}', ['DATA_PATH' => ''], '/srv/app/config.yml'],
    'set value over default' => ['${DATA_PATH:-/srv/app/config.yml}', ['DATA_PATH' => '/mnt/config.yml'], '/mnt/config.yml'],
    'variable in default' => ['${DATA:-/srv/$HOME/config.yml}', ['DATA' => '', 'HOME' => 'tenant'], '/srv/tenant/config.yml'],
    'quotes in default' => ['${DATA:-/srv/my "data"/config.yml}', ['DATA' => ''], '/srv/my "data"/config.yml'],
    'expanded value is not shell code' => ['$DATA_PATH/config.yml', ['DATA_PATH' => '$(printf injected)'], '$(printf injected)/config.yml'],
]);

it('rejects unsupported persisted Compose expressions before shell use', function (string $path) {
    expect(fn () => filesystemVolumeShellArgument($path))->toThrow(Exception::class);
})->with(['${DATA:+/srv/app}', '${DATA:-${HOME}/config.yml}', '${DATA:-/srv/app}/file', '${DATA:?missing}', '${DATA?missing}', '${DATA-/srv/app}', '${DATA+/srv/app}']);

it('quotes literal file-storage paths and safely expands persisted expressions', function () {
    $user = User::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $user->teams()->first()->id]);
    Storage::fake('ssh-keys');
    $server = Server::factory()->create([
        'team_id' => $user->teams()->first()->id,
        'private_key_id' => $privateKey->id,
    ]);

    $literal = Mockery::mock(LocalFileVolume::class)->makePartial();
    $literal->fs_path = '/data/my files/config.yaml';
    $literal->is_directory = true;
    $literal->shouldReceive('save')->once();
    $file = Mockery::mock(LocalFileVolume::class)->makePartial();
    $file->fs_path = '/data/my files/settings.json';
    $file->content = '{}';
    $file->is_directory = false;
    $file->shouldReceive('save')->once();
    $expression = Mockery::mock(LocalFileVolume::class)->makePartial();
    $expression->fs_path = '${DATA_PATH:-/srv/app/config.yaml}';
    $expression->is_directory = true;
    $expression->shouldReceive('save')->once();

    $fileStorages = Mockery::mock(MorphMany::class);
    $fileStorages->shouldReceive('get')->once()->andReturn(collect([$literal, $file, $expression]));

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('getMorphClass')->andReturn(Application::class);
    $application->shouldReceive('workdir')->once()->andReturn('/data/application');
    $application->shouldReceive('fileStorages')->once()->andReturn($fileStorages);
    $application->setRelation('destination', (object) ['server' => $server]);

    Process::fake(fn ($process) => Process::result(output: str_contains($process->command, 'test -') ? 'NOK' : ''));
    getFilesystemVolumesFromServer($application, true);

    Process::assertRan(fn ($process) => str_contains($process->command, "test -f '/data/my files/config.yaml'"));
    Process::assertRan(fn ($process) => str_contains($process->command, "mkdir -p -- '/data/my files/config.yaml'"));
    Process::assertRan(fn ($process) => str_contains($process->command, "dirname -- '/data/my files/settings.json'"));
    Process::assertRan(fn ($process) => str_contains($process->command, "tee -- '/data/my files/settings.json'"));
    Process::assertRan(fn ($process) => str_contains($process->command, '${DATA_PATH:-/srv/app/config.yaml}'));
});

it('bounds the remote file read itself to prevent a size-check race', function () {
    $source = remoteOutputSource('app/Models/LocalFileVolume.php');
    $loadStorage = str($source)
        ->after('public function loadStorageOnServer()')
        ->before('public function deleteStorageOnServer()');

    expect($loadStorage->value())
        ->toContain('head -c')
        ->not->toContain('instant_remote_process(["cat {$escapedPath}"]');
});

it('bounds directory-to-file conflict reads the same way', function () {
    $source = remoteOutputSource('app/Models/LocalFileVolume.php');
    $saveStorage = str($source)
        ->after('public function saveStorageOnServer()')
        ->before('protected function plainMountPath');

    expect($saveStorage->value())
        ->not->toContain('instant_remote_process(["cat {$escapedPath}"]');
});

it('treats a bounded remote read that exceeds the limit as too large', function () {
    $oversized = str_repeat('a', LocalFileVolume::MAX_CONTENT_SIZE + 1);

    expect(LocalFileVolume::contentFromBoundedRead($oversized))
        ->toBe(LocalFileVolume::TOO_LARGE_PLACEHOLDER);
});

it('keeps a bounded remote read that fits the limit', function () {
    $maximumSizedContent = str_repeat('a', LocalFileVolume::MAX_CONTENT_SIZE);

    expect(LocalFileVolume::contentFromBoundedRead('hello'))
        ->toBe('hello')
        ->and(LocalFileVolume::contentFromBoundedRead($maximumSizedContent))
        ->toBe($maximumSizedContent)
        ->and(LocalFileVolume::contentFromBoundedRead(null))
        ->toBe('');
});
