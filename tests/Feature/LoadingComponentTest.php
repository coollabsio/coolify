<?php

it('uses the current neutral loading indicator styles', function () {
    $loading = file_get_contents(resource_path('views/components/loading.blade.php'));

    expect($loading)
        ->toContain('gap-2 text-[13px] text-neutral-500 dark:text-fg-dim')
        ->toContain("'size-3' => \$compact")
        ->toContain("'size-4' => ! \$compact")
        ->toContain('stroke="currentColor" stroke-width="2"')
        ->not->toContain('text-coollabs')
        ->not->toContain('dark:text-warning');
});

it('shows runtime container loading inside a settings card', function () {
    $logs = file_get_contents(resource_path('views/livewire/project/shared/logs.blade.php'));

    expect($logs)
        ->toContain('application-settings-section-body flex min-h-40 w-full items-center justify-center')
        ->toContain('<x-loading text="Loading containers" />');
});
