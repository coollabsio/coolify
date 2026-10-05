<?php

use Symfony\Component\Finder\Finder;

it('uses the confirmation modal instead of native browser confirm dialogs', function () {
    $viewsPath = dirname(__DIR__, 2).'/resources/views';

    $offenders = collect(Finder::create()->files()->in($viewsPath)->name('*.blade.php'))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/wire:confirm|(?<![\w.$>])confirm\(/', file_get_contents($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => str($file->getPathname())->after($viewsPath.'/')->toString())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
