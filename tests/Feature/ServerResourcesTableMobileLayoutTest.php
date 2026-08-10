<?php

test('server resources managed table collapses to name and status on mobile', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.server-resources-managed-table-grid')
        ->toContain('.server-resources-unmanaged-table-grid');

    // Isolate the ≤640px mobile block so we assert the correct breakpoint rules.
    expect(preg_match(
        '/@media \(max-width: 640px\)\s*\{(?P<body>.*)\n\}/s',
        $css,
        $matches
    ))->toBe(1);

    $mobileCss = $matches['body'] ?? '';

    // Managed: hide Project, Environment, and Type — Name + Status only.
    expect($mobileCss)
        ->toMatch('/\.server-resources-managed-table-grid\s*\{\s*grid-template-columns:\s*minmax\(0,\s*1fr\)\s+auto;/')
        ->toContain('.server-resources-managed-table-grid > :nth-child(2)')
        ->toContain('.server-resources-managed-table-grid > :nth-child(3)')
        ->toContain('.server-resources-managed-table-grid > :nth-child(4)');

    // Unmanaged: hide Image and Status — Name + Actions only.
    expect($mobileCss)
        ->toMatch('/\.server-resources-unmanaged-table-grid\s*\{\s*grid-template-columns:\s*minmax\(0,\s*1fr\)\s+auto;/')
        ->toContain('.server-resources-unmanaged-table-grid > :nth-child(2)')
        ->toContain('.server-resources-unmanaged-table-grid > :nth-child(3)');
});

test('server resources table markup keeps five managed columns for progressive disclosure', function () {
    $view = file_get_contents(resource_path('views/livewire/server/resources.blade.php'));

    expect($view)
        ->toContain('server-resources-managed-table-grid')
        ->toContain('server-resources-unmanaged-table-grid')
        ->toContain('<span>Name</span>')
        ->toContain('<span>Project</span>')
        ->toContain('<span>Environment</span>')
        ->toContain('<span>Type</span>')
        ->toContain('<span>Status</span>');
});

test('unmanaged container names use the same typeface as managed resource names', function () {
    $view = file_get_contents(resource_path('views/livewire/server/resources.blade.php'));

    // Managed names: sans + medium weight (linked).
    expect($view)
        ->toContain('block max-w-full truncate text-[12px] font-medium text-neutral-950 hover:underline dark:text-fg');

    // Unmanaged names must match size/weight/color and not use mono.
    expect($view)
        ->toContain('min-w-0 truncate text-[12px] font-medium text-neutral-950 dark:text-fg')
        ->not->toContain('truncate font-mono text-[12px] text-neutral-950 dark:text-fg');
});

test('server resource tabs show a loading state while switching', function () {
    $view = file_get_contents(resource_path('views/livewire/server/resources.blade.php'));

    expect(substr_count($view, 'wire:loading.attr="disabled" wire:target="loadManagedContainers,loadUnmanagedContainers"'))
        ->toBe(2)
        ->and($view)
        ->toContain('<x-loading-on-button wire:loading wire:target="loadManagedContainers" />')
        ->toContain('<x-loading-on-button wire:loading wire:target="loadUnmanagedContainers" />');
});
