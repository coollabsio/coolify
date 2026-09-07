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
        ->toContain('aria-label="Refresh status"')
        ->toContain('<x-reicon name="chevron-down"')
        ->toContain(":class=\"open && 'rotate-180'\"")
        ->toContain('wire:click="checkProxyStatus"')
        ->toContain('wire:loading.class="animate-spin" wire:target="checkProxyStatus"')
        ->toContain('name="refresh" class="size-3"')
        ->not->toContain('Refreshing status')
        ->not->toContain('<x-loading compact />')
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

it('lists desktop proxy controls inline and keeps Traefik and refresh in Advanced', function () {
    $navbar = file_get_contents(resource_path('views/livewire/server/navbar.blade.php'));
    $overflow = file_get_contents(resource_path('views/components/resource-heading-overflow.blade.php'));
    $advanced = file_get_contents(resource_path('views/components/server/advanced.blade.php'));
    $desktopActions = str($navbar)->after("@teleport('#resource-action-hud-slot')")->before('@endteleport')->toString();

    expect($desktopActions)
        ->toContain('Restart Proxy')
        ->toContain('Stop Proxy')
        ->toContain('Start Proxy')
        ->toContain('<x-server.advanced')
        ->not->toContain('Traefik Dashboard')
        ->not->toContain('Refresh Proxy Status')
        ->not->toContain('resource-heading-overflow-separator')
        ->not->toContain('<x-modal-confirmation');

    expect(strpos($desktopActions, '<x-server.advanced'))
        ->toBeLessThan(strpos($desktopActions, 'id="server-desktop-actions"'));

    expect($advanced)
        ->toContain('Advanced')
        ->toContain('name="grid"')
        ->toContain('Traefik Dashboard')
        ->toContain('name="external-link" class="size-3! opacity-70"')
        ->toContain('class="flex size-4 shrink-0 items-center justify-center"')
        ->toContain('Refresh Proxy Status');

    expect($overflow)
        ->toContain('Actions')
        ->toContain('listbox-panel');
});
