<?php

function rayRemovalFiles(string $directory): array
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    $files = [];

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function rayRemovalBasePath(string $path = ''): string
{
    return dirname(__DIR__, 2).($path === '' ? '' : DIRECTORY_SEPARATOR.$path);
}

it('does not include Ray as a dependency', function () {
    $composer = json_decode(file_get_contents(rayRemovalBasePath('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode(file_get_contents(rayRemovalBasePath('composer.lock')), true, flags: JSON_THROW_ON_ERROR);
    $lockedPackageNames = collect([
        ...($lock['packages'] ?? []),
        ...($lock['packages-dev'] ?? []),
    ])->pluck('name');

    expect($composer['require'] ?? [])->not->toHaveKey('spatie/laravel-ray')
        ->and($composer['require-dev'] ?? [])->not->toHaveKey('spatie/laravel-ray')
        ->and($lockedPackageNames)->not->toContain('spatie/laravel-ray', 'spatie/ray');
});

it('does not ship Ray configuration', function () {
    expect(file_exists(rayRemovalBasePath('config/ray.php')))->toBeFalse();
});

it('does not call ray from application code', function () {
    $files = [
        ...rayRemovalFiles(rayRemovalBasePath('app')),
        ...rayRemovalFiles(rayRemovalBasePath('bootstrap/helpers')),
    ];

    $rayCalls = collect($files)
        ->filter(fn (string $file): bool => preg_match('/\bray\s*\(/', file_get_contents($file)) === 1)
        ->map(fn (string $file): string => str_replace(rayRemovalBasePath().'/', '', $file))
        ->values();

    expect($rayCalls)->toBeEmpty();
});
