<?php

it('uses left aligned single color status badges in the server header', function () {
    $navbarView = file_get_contents(resource_path('views/livewire/server/navbar.blade.php'));
    $badgeView = file_get_contents(resource_path('views/components/status-badge.blade.php'));

    expect($navbarView)
        ->toContain('data-testid="server-status-summary"')
        ->toContain('<x-status-badge')
        ->toContain('label="Proxy"')
        ->toContain('label="Sentinel"')
        ->toContain('as="button"')
        ->toContain("wire:click='checkProxyStatus'")
        ->toContain('status="Refresh"')
        ->toContain('border-transparent')
        ->toContain('wire:loading.attr="disabled"')
        ->not->toContain('status="Refreshing..."')
        ->not->toContain('justify-between')
        ->not->toContain('<x-status.stopped status="Proxy Exited" noLoading />')
        ->not->toContain('<x-status.running status="Sentinel In Sync" noLoading />');

    expect($badgeView)
        ->not->toContain('text-neutral-500')
        ->not->toContain('dark:text-neutral-400')
        ->toContain('<button')
        ->toContain("merge(['type' => 'button'])");
});
