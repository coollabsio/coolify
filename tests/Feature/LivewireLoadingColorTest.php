<?php

test('livewire loading indicators use the shared coollabs purple', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));
    $loading = file_get_contents(resource_path('views/components/loading.blade.php'));
    $pageLoading = file_get_contents(resource_path('views/components/page-loading.blade.php'));
    $views = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file): string => file_get_contents($file->getPathname()))
        ->implode("\n");

    expect($utilities)
        ->toContain('@utility loading-indicator')
        ->toContain('@apply text-coollabs dark:text-coollabs-100;')
        ->and($loading)->toContain('loading-indicator')
        ->and($pageLoading)->toContain('loading-indicator')
        ->and($views)->not->toMatch('/animate-spin[^"\n]*dark:text-warning|dark:text-warning[^"\n]*animate-spin/');
});

test('livewire navigation progress bar uses coollabs purple', function () {
    expect(config('livewire.navigate.progress_bar_color'))->toBe('#6b16ed');
});
