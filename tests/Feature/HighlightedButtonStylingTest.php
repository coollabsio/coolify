<?php

test('highlighted buttons use the shared coollabs style in every color scheme', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));
    $appStyles = file_get_contents(resource_path('css/app.css'));
    $views = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file): string => file_get_contents($file->getPathname()))
        ->implode("\n");

    expect($utilities)
        ->toContain('@utility button-highlighted')
        ->toContain('@apply border-2 text-coollabs-200 dark:text-white bg-coollabs-50 dark:bg-coollabs/20 border-coollabs dark:border-coollabs-100 hover:bg-coollabs hover:text-white dark:hover:bg-coollabs-100 dark:hover:text-white;')
        ->and($appStyles)
        ->toContain('button[isHighlighted]:not(:disabled)')
        ->toContain('@apply button-highlighted;')
        ->and($views)
        ->not->toContain('dark:bg-warning/15! dark:text-warning! dark:ring-warning/25');
});
