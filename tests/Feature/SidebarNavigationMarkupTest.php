<?php

it('keeps server submenu state independent from the Livewire update route', function () {
    $sidebar = file_get_contents(resource_path('views/components/server/sidebar.blade.php'));
    $dynamicConfigurations = file_get_contents(resource_path('views/livewire/server/proxy/dynamic-configurations.blade.php'));
    $proxyConfiguration = file_get_contents(resource_path('views/livewire/server/proxy/show.blade.php'));
    $proxyLogs = file_get_contents(resource_path('views/livewire/server/proxy/logs.blade.php'));

    expect($sidebar)
        ->toContain("'active' => \$activeMenu === 'proxy'")
        ->toContain("'active' => \$activeSubMenu === 'dynamic-confs'")
        ->not->toContain("'active' => request()->routeIs('server.proxy.dynamic-confs')")
        ->and($dynamicConfigurations)->toContain('activeSubMenu="dynamic-confs"')
        ->and($proxyConfiguration)->toContain('activeSubMenu="configuration"')
        ->and($proxyLogs)->toContain('activeSubMenu="logs"');
});

it('keeps the server resources menu active during Livewire updates', function () {
    $sidebar = file_get_contents(resource_path('views/components/server/sidebar.blade.php'));

    expect($sidebar)
        ->toContain("'label' => 'Resources',\n            'route' => 'server.resources',\n            'active' => \$activeMenu === 'resources'")
        ->not->toContain("'active' => request()->routeIs('server.resources')");
});

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

it('does not separate the desktop sidebar controls with a top border', function () {
    $navbar = file_get_contents(resource_path('views/components/navbar.blade.php'));

    expect($navbar)
        ->toContain('sticky bottom-0 mt-auto -mx-2 hidden items-center gap-1 bg-white')
        ->not->toContain('sticky bottom-0 mt-auto -mx-2 hidden items-center gap-1 border-t');
});

it('draws the desktop header border only above the main content', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toContain('flex items-center gap-2 h-full shrink-0 border-r border-neutral-200')
        ->toContain('flex h-full items-center gap-0.5 min-w-0 flex-1 border-b border-neutral-200 pl-3 pr-4 dark:border-white/[0.06]')
        ->not->toContain('backdrop-blur border-b border-neutral-200 dark:border-white/[0.06]');
});

it('separates the mobile sidebar from the page with a visible border', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->toContain('max-w-56 min-w-0 flex-col border-l border-neutral-200 bg-white shadow-xl dark:border-white/[0.12] dark:bg-panel');
});

it('keeps the mobile navbar above floating configuration warnings', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $popup = file_get_contents(resource_path('views/components/popup-small.blade.php'));

    expect($layout)
        ->toContain('class="relative z-[1000] lg:hidden"')
        ->and($popup)->toContain('z-999');
});

it('keeps compact configuration warnings the same width as their expanded state', function () {
    $popup = file_get_contents(resource_path('views/components/popup-small.blade.php'));

    expect($popup)
        ->toContain("? 'w-[calc(100vw-2rem)] max-w-sm cursor-pointer'")
        ->toContain(": 'w-[calc(100vw-2rem)] max-w-sm'")
        ->not->toContain('sm:w-auto');
});

it('provides configuration warning slots in both desktop and mobile navbars', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toContain('id="configuration-warning-hud-slot"')
        ->toContain('id="configuration-warning-hud-slot-mobile"');
});

it('shows the full team name in the header', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $switcher = file_get_contents(resource_path('views/livewire/switch-team.blade.php'));

    expect($layout)
        ->toContain('flex min-w-0 flex-1 items-center gap-2.5')
        ->toContain('size-8 shrink-0 items-center justify-center rounded-lg')
        ->toContain('flex shrink-0 items-center gap-1')
        ->and($switcher)
        ->toContain('group/team flex h-8 items-center')
        ->toContain('whitespace-nowrap text-[13px]')
        ->not->toContain('max-w-56 truncate text-[13px]');
});

it('allows more of the team name to display in the desktop breadcrumb', function () {
    $breadcrumb = file_get_contents(resource_path('views/components/top-breadcrumb.blade.php'));

    expect($breadcrumb)
        ->toContain('class="shrink-0" x-data="{ collapsed: false }"')
        ->toContain('<div class="flex min-w-0 items-center gap-0.5 text-[13px]">')
        ->not->toContain('<div class="flex w-full min-w-0 items-center gap-0.5 text-[13px]">');

    $switcher = file_get_contents(resource_path('views/livewire/switch-team.blade.php'));

    expect($switcher)->toContain('whitespace-nowrap text-[13px]');
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

it('uses the structured sidebar and action HUD for server navigation', function () {
    $navbar = file_get_contents(resource_path('views/livewire/server/navbar.blade.php'));
    $sidebar = file_get_contents(resource_path('views/components/server/sidebar.blade.php'));
    $resources = file_get_contents(resource_path('views/livewire/server/resources.blade.php'));
    $terminal = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));

    expect($navbar)
        ->toContain('class="hidden xl:fixed xl:top-14 xl:right-4 xl:z-30 xl:flex')
        ->toContain('<x-resource-heading-tabs class="hidden" aria-hidden="true">')
        ->toContain('dark:bg-coolgray-100! dark:text-white!')
        ->and($sidebar)
        ->toContain("'label' => 'Proxy'")
        ->toContain("'label' => 'Sentinel'")
        ->toContain("'label' => 'Resources'")
        ->toContain("'label' => 'Terminal'")
        ->toContain("'label' => 'Security'")
        ->and($resources)->toContain('<x-server.sidebar :server="$server" activeMenu="resources" />')
        ->and($terminal)->toContain('<x-server.sidebar :server="$resource" activeMenu="terminal" />');
});
