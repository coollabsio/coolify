<?php

/**
 * Unit tests for LocalFileVolume content size handling.
 *
 * Related Issue: #4701 - Storages page becomes unusable when Docker volumes
 * mount large host files. Coolify previously stored full file content in the
 * encrypted `content` mediumText column, then serialized it to the Livewire
 * payload, crashing the browser.
 */

use App\Models\LocalFileVolume;
use Tests\TestCase;

uses(TestCase::class);

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
    $helpers = file_get_contents(base_path('bootstrap/helpers/services.php'));
    $filesystemSync = str($helpers)
        ->after('function getFilesystemVolumesFromServer')
        ->before('function updateCompose');

    expect($filesystemSync->value())
        ->not->toContain('instant_remote_process(["cat $fileLocation"]');
    expect($filesystemSync->value())
        ->toContain('if ($fileVolume->is_based_on_git)')
        ->toContain('$fileVolume->loadStorageOnServer();');
});

it('marks git-based file volumes as files before refreshing their content', function () {
    $helpers = file_get_contents(base_path('bootstrap/helpers/services.php'));
    $fileBranch = str($helpers)
        ->after("if (\$isFile === 'OK') {")
        ->before("} elseif (\$isDir === 'OK') {");

    expect($fileBranch->value())->toMatch(
        '/\$fileVolume->is_directory = false;\s+\$fileVolume->save\(\);\s+if \(\$fileVolume->is_based_on_git\) \{/'
    );
});

it('bounds the remote file read itself to prevent a size-check race', function () {
    $source = file_get_contents(app_path('Models/LocalFileVolume.php'));
    $loadStorage = str($source)
        ->after('public function loadStorageOnServer()')
        ->before('public function deleteStorageOnServer()');

    expect($loadStorage->value())
        ->toContain('head -c')
        ->not->toContain('instant_remote_process(["cat {$escapedPath}"]');
});

it('bounds directory-to-file conflict reads the same way', function () {
    $source = file_get_contents(app_path('Models/LocalFileVolume.php'));
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
    expect(LocalFileVolume::contentFromBoundedRead('hello'))
        ->toBe('hello')
        ->and(LocalFileVolume::contentFromBoundedRead(null))
        ->toBe('');
});
