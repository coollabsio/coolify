<?php

use Symfony\Component\Finder\Finder;

it('does not use native browser dialogs in views or scripts', function () {
    $files = Finder::create()
        ->files()
        ->in([__DIR__.'/../../resources/views', __DIR__.'/../../resources/js'])
        ->name(['*.blade.php', '*.js']);

    $offenders = [];
    foreach ($files as $file) {
        foreach (explode("\n", $file->getContents()) as $number => $line) {
            if (preg_match('/wire:confirm|(?<![\w.$])(?:window\.)?(?:alert|confirm|prompt)\s*\(/', $line)) {
                $offenders[] = $file->getRelativePathname().':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBeEmpty();
});
