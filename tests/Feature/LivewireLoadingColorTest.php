<?php

test('loading indicators use warning yellow throughout dark mode', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));
    $appCss = file_get_contents(resource_path('css/app.css'));
    $loading = file_get_contents(resource_path('views/components/loading.blade.php'));
    $pageLoading = file_get_contents(resource_path('views/components/page-loading.blade.php'));
    expect($utilities)
        ->toContain('@utility loading-indicator')
        ->toContain('@apply text-coollabs dark:text-warning;')
        ->and($loading)->toContain('loading-indicator')
        ->and($pageLoading)->toContain('loading-indicator')
        ->and($appCss)->toContain('.dark .animate-spin')
        ->toContain('color: var(--color-warning) !important;')
        ->toContain('.dark #nprogress .bar')
        ->toContain('background: var(--color-warning) !important;');
});

test('livewire navigation progress bar uses coollabs purple', function () {
    expect(config('livewire.navigate.progress_bar_color'))->toBe('#6b16ed');
});
