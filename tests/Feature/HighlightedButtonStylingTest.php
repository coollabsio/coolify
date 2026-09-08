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
        ->toContain('@apply border-coollabs-200 bg-linear-to-b from-coollabs-100 to-coollabs-200 text-accent-foreground! hover:from-coollabs-100 hover:to-coollabs hover:text-accent-foreground!;')
        ->and($appStyles)
        ->toContain('button[isHighlighted]:not(:disabled)')
        ->toContain('@apply button-highlighted;')
        ->and($views)
        ->not->toContain('dark:bg-warning/15! dark:text-warning! dark:ring-warning/25');
});

test('custom theme highlighted buttons use the computed contrasting foreground', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));
    $appStyles = file_get_contents(resource_path('css/app.css'));

    expect($utilities)
        ->toContain('text-accent-foreground!')
        ->toContain('hover:text-accent-foreground!')
        ->not->toContain('text-white! hover:')
        ->and($appStyles)
        ->toContain('html[data-theme="custom"] .button-highlighted .animate-spin')
        ->toContain('html[data-theme="custom"] button[isHighlighted] .animate-spin');
});
