<?php

it('initializes persisted sidebar state before enabling layout transitions', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toContain("collapsed: localStorage.getItem('sidebarCollapsed') === 'true'")
        ->toContain('sidebarReady: false')
        ->toContain(":class=\"[collapsed ? 'lg:w-16' : 'lg:w-56', sidebarReady ? 'transition-[width] duration-200' : '']\"")
        ->toContain(":class=\"[collapsed ? 'lg:ml-16' : 'lg:ml-56', sidebarReady ? 'transition-[margin] duration-200' : '']\"");
});

it('shows the coolify icon in the collapsed desktop brand slot', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toContain('x-show="collapsed" x-cloak src="/coolify-logo.svg" alt="Coolify"')
        ->not->toContain('>C</span>');
});

it('does not animate navbar padding when restoring collapsed state', function () {
    $navbar = file_get_contents(resource_path('views/components/navbar.blade.php'));

    expect($navbar)
        ->not->toContain('items-start gap-3 motion-safe:transition-all')
        ->not->toContain('overflow-hidden motion-safe:transition-all');
});
