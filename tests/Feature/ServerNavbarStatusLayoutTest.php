<?php

it('collapses server subsystem badges into one status summary', function () {
    $navbarView = file_get_contents(resource_path('views/livewire/server/navbar.blade.php'));
    $summaryView = file_get_contents(resource_path('views/components/server/status-summary.blade.php'));
    $badgeView = file_get_contents(resource_path('views/components/status-badge.blade.php'));

    expect($navbarView)
        ->toContain('<x-server.status-summary')
        ->not->toContain('label="Proxy" status="Running"')
        ->not->toContain('label="Sentinel"')
        ->and($summaryView)
        ->toContain("['Attention required', 'warning']")
        ->toContain("['Ready', 'success']")
        ->toContain('System status')
        ->toContain('Refresh status')
        ->toContain('<x-reicon name="chevron-down"')
        ->toContain(":class=\"open && 'rotate-180'\"")
        ->toContain('wire:click="checkProxyStatus"')
        ->toContain('wire:loading.remove wire:target="checkProxyStatus"')
        ->toContain('wire:loading.flex wire:target="checkProxyStatus"')
        ->toContain('Refreshing status')
        ->toContain('<x-loading compact />')
        ->toContain('name="refresh" class="size-3 opacity-70"')
        ->not->toContain('@click="open = false" role="menuitem"');

    expect($badgeView)
        ->not->toContain('text-neutral-500')
        ->not->toContain('dark:text-neutral-400')
        ->toContain('<button')
        ->toContain("merge(['type' => 'button'])");
});
