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

it('draws a single border between the desktop sidebar and main content', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $navbar = file_get_contents(resource_path('views/components/navbar.blade.php'));

    expect($navbar)->toContain('border-r border-neutral-200')
        ->and($layout)->not->toContain('lg:border-l border-neutral-200');
});

it('shows section titles and descriptions above settings navigation on smaller screens', function (string $view, string $title, string $description) {
    $layout = file_get_contents(resource_path("views/components/{$view}"));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($layout)
        ->toContain('<header class="settings-mobile-header xl:hidden">')
        ->toContain("<h1 class=\"settings-mobile-title\">{$title}</h1>")
        ->toContain("<p class=\"settings-mobile-description\">{$description}</p>")
        ->toContain('<section class="application-settings-workspace w-full max-w-[1180px]">')
        ->and($css)
        ->toContain('.settings-mobile-title')
        ->toContain('.settings-mobile-description')
        ->toContain('color: #000000;')
        ->toContain('.dark .settings-mobile-title')
        ->toContain('color: #ffffff;');
})->with([
    ['notification/settings-layout.blade.php', 'Notifications', 'Configure how your team receives deployment and system alerts.'],
    ['security/settings-layout.blade.php', 'Keys & Tokens', 'Manage SSH keys, cloud credentials, and API access tokens.'],
    ['settings/layout.blade.php', 'Instance Settings', 'Configure global settings for this Coolify instance.'],
    ['team/settings-layout.blade.php', 'Team', 'Manage your team, members, and access settings.'],
]);
