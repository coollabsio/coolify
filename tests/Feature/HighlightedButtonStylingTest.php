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
        ->toContain('@apply border-coollabs-200 bg-linear-to-b from-coollabs-100 to-coollabs-200 text-white! hover:from-coollabs-100 hover:to-coollabs hover:text-white!;')
        ->and($appStyles)
        ->toContain('button[isHighlighted]:not(:disabled)')
        ->toContain('@apply button-highlighted;')
        ->and($views)
        ->not->toContain('dark:bg-warning/15! dark:text-warning! dark:ring-warning/25');
});
