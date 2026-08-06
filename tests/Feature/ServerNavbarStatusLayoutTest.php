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
        ->toContain('name="refresh" class="size-2.5 opacity-70"')
        ->toContain('min-h-0! h-7! gap-1.5! px-2! py-1! text-[11px]')
        ->toContain("href=\"{{ route('server.proxy', ['server_uuid' => \$server->uuid]) }}\"")
        ->toContain("href=\"{{ route('server.sentinel', ['server_uuid' => \$server->uuid]) }}\"")
        ->toContain('@click="open = false" role="menuitem"');

    expect($badgeView)
        ->not->toContain('text-neutral-500')
        ->not->toContain('dark:text-neutral-400')
        ->toContain('<button')
        ->toContain("merge(['type' => 'button'])");
});

it('uses the branded input focus state for the server filter', function () {
    $navbarView = file_get_contents(resource_path('views/livewire/server/navbar.blade.php'));

    expect($navbarView)
        ->toContain('placeholder="Filter servers…"')
        ->toContain('class="input h-7!')
        ->toContain('<x-reicon name="check-circle"')
        ->not->toContain('<x-reicon name="check"');
});
