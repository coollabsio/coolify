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
        if (str_contains($process->command, 'test -f /data/large.bin')) {
            return Process::result(output: 'OK');
        }

        if (str_contains($process->command, 'test -d /data/large.bin')) {
            return Process::result(output: 'NOK');
        }

        return Process::result();
    });

    getFilesystemVolumesFromServer($application);

    expect($volume->is_directory)->toBeFalse();
    Process::assertRan(fn ($process) => str_contains($process->command, 'test -f /data/large.bin'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'cat /data/large.bin') || str_contains($process->command, 'head -c'));
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
