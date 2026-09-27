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
        ->toContain('M8 9l4-4 4 4M8 15l4 4 4-4')
        ->not->toContain('<x-reicon name="chevron-down" class="size-3 shrink-0 text-neutral-400 dark:text-fg-faint" />')
        ->toContain('<x-reicon name="check-circle"')
        ->not->toContain('<x-reicon name="check"');
});

it('lists desktop proxy controls in one split action and links Traefik beside it', function () {
    $navbar = file_get_contents(resource_path('views/livewire/server/navbar.blade.php'));
    $overflow = file_get_contents(resource_path('views/components/resource-heading-overflow.blade.php'));
    $desktopActions = str($navbar)->after("@teleport('#resource-action-hud-slot')")->before('@endteleport')->toString();
    $splitAction = str($desktopActions)->after('id="server-desktop-actions"')->before('</x-split-action>')->toString();
    $traefikLink = str($desktopActions)->before('<x-split-action id="server-desktop-actions"')->toString();

    expect($desktopActions)
        ->toContain('Restart Proxy')
        ->toContain('Stop Proxy')
        ->toContain('Start Proxy')
        ->toContain('<x-split-action id="server-desktop-actions"')
        ->toContain('Traefik Dashboard')
        ->not->toContain('<x-server.advanced')
        ->not->toContain('resource-heading-overflow-separator')
        ->not->toContain('<x-modal-confirmation');

    expect(strpos($desktopActions, 'Traefik Dashboard'))
        ->toBeLessThan(strpos($desktopActions, 'id="server-desktop-actions"'));

    expect($traefikLink)
        ->toContain('@if ($traefikDashboardAvailable)')
        ->toContain('target="_blank"')
        ->toContain('href="http://{{ $serverIp }}:8080"')
        ->toContain('name="external-link" class="size-3.5 shrink-0 opacity-70"');

    expect($splitAction)
        ->toContain('<x-slot:main')
        ->toContain('listbox-option')
        ->toContain('wire:click="checkProxyStatus"')
        ->toContain('Refresh Proxy Status')
        ->not->toContain('Traefik');

    expect($overflow)
        ->toContain('Actions')
        ->toContain('listbox-panel');
});
