<?php

it('uses neutral play icons for resource start actions', function () {
    foreach ([
        'application',
        'database',
        'service',
    ] as $resource) {
        $heading = file_get_contents(resource_path("views/livewire/project/{$resource}/heading.blade.php"));

        // One neutral start icon in the mobile split action and one in the desktop split action.
        expect(substr_count($heading, 'name="play-circle" class="size-3.5"'))
            ->toBeGreaterThanOrEqual(2);

        expect($heading)
            ->not->toContain('name="play-circle" class="size-3.5 text-warning"')
            ->not->toContain('name="play-circle" class="size-3.5 text-orange-500');
    }
});

it('uses full-width split action menus on mobile resource headings', function () {
    $headings = [
        'application' => file_get_contents(resource_path('views/livewire/project/application/heading.blade.php')),
        'database' => file_get_contents(resource_path('views/livewire/project/database/heading.blade.php')),
        'service' => file_get_contents(resource_path('views/livewire/project/service/heading.blade.php')),
    ];

    foreach ($headings as $resource => $heading) {
        expect(mobileActionsAreBeforeDesktopActions($heading, "{$resource}-mobile-actions", "{$resource}-desktop-actions"))->toBeTrue();

        expect($heading)
            ->toContain('<div class="w-full xl:hidden">')
            ->toContain("<x-split-action id=\"{$resource}-mobile-actions\" class=\"mb-3 flex w-full\">")
            ->not->toContain("{$resource}-mobile-section")
            ->not->toContain('<select')
            ->not->toContain('<optgroup')
            ->not->toContain('@selected');

        expect(mobileActionsMarkup($heading, "{$resource}-mobile-actions"))
            ->toContain('<x-slot:main')
            ->toContain('listbox-option justify-start! gap-2.5!')
            ->toContain('role="menuitem"')
            ->toContain('name="play-circle" class="size-3.5"')
            ->toContain('name="restart" class="size-3.5')
            ->toContain('name="stop-circle" class="size-3.5 text-error"');
    }

    expect(mobileActionsMarkup($headings['application'], 'application-mobile-actions'))
        ->toContain('wire:click="deploy"')
        ->toContain('wire:click="deploy(true)"')
        ->toContain('force_deploy_without_cache')
        ->toContain('Deploy (without cache)')
        ->toContain("document.getElementById('application-mobile-stop-trigger')?.click()")
        ->toContain("document.getElementById('application-mobile-restart-trigger')?.click()");

    expect($headings['application'])
        ->toContain('<button id="application-mobile-stop-trigger" type="button">')
        ->toContain('<button id="application-mobile-restart-trigger" type="button">')
        ->not->toContain('application-mobile-deploy-trigger')
        ->not->toContain('application-mobile-force-deploy-trigger')
        ->not->toContain('Confirm Application Deployment?');

    expect(mobileActionsMarkup($headings['database'], 'database-mobile-actions'))
        ->toContain('x-bind:disabled="busy"')
        ->toContain("document.getElementById('database-restart-trigger')?.click()")
        ->toContain("document.getElementById('database-stop-trigger')?.click()")
        ->toContain("\$wire.dispatch('startEvent')");

    expect($headings['database'])
        ->toContain('<button id="database-restart-trigger" type="button">')
        ->toContain('<button id="database-stop-trigger" type="button">')
        ->not->toContain('database-start-trigger')
        ->not->toContain('Confirm Database Start?');

    expect(mobileActionsMarkup($headings['service'], 'service-mobile-actions'))
        ->toContain('x-bind:disabled="deploying"')
        ->toContain("document.getElementById('service-restart-trigger')?.click()")
        ->toContain("document.getElementById('service-stop-trigger')?.click()")
        ->toContain("\$wire.dispatch('startEvent')")
        ->toContain("\$wire.dispatch('forceDeployEvent')")
        ->toContain("\$wire.dispatch('pullAndRestartEvent')");

    expect($headings['service'])
        ->toContain('<button id="service-restart-trigger" type="button">')
        ->toContain('<button id="service-stop-trigger" type="button">')
        ->not->toContain('service-start-trigger')
        ->not->toContain('service-forceDeploy-trigger')
        ->not->toContain('service-pullAndRestart-trigger')
        ->not->toContain('Confirm Service Deployment?')
        ->not->toContain('Confirm Service Force Deployment?');
});

function mobileActionsMarkup(string $heading, string $actionsId): string
{
    $actionsPosition = strpos($heading, 'id="'.$actionsId.'"');

    if ($actionsPosition === false) {
        return '';
    }

    $endPosition = strpos($heading, '</x-split-action>', $actionsPosition);

    if ($endPosition === false) {
        return '';
    }

    return substr($heading, $actionsPosition, $endPosition - $actionsPosition);
}

function mobileActionsAreBeforeDesktopActions(string $heading, string $mobileActionsId, string $desktopActionsId): bool
{
    $mobilePosition = strpos($heading, 'id="'.$mobileActionsId.'"');
    $hudPosition = strpos($heading, "@teleport('#resource-action-hud-slot')");
    $desktopPosition = strpos($heading, 'id="'.$desktopActionsId.'"');

    return $mobilePosition !== false
        && $hudPosition !== false
        && $desktopPosition !== false
        && $mobilePosition < $hudPosition
        && $hudPosition < $desktopPosition;
}

it('places database backups immediately after configuration in navigation menus', function () {
    $databaseHeading = file_get_contents(resource_path('views/livewire/project/database/heading.blade.php'));
    $mobileMenuItems = str($databaseHeading)->between('$databasePageItems = [', '$databaseConfigurationItems = [')->toString();
    $desktopMenuItems = str($databaseHeading)->between('class="scrollbar hidden', '</nav>')->toString();

    foreach ([$mobileMenuItems, $desktopMenuItems] as $menuItems) {
        expect(strpos($menuItems, 'Configuration'))
            ->toBeLessThan(strpos($menuItems, 'Backups'))
            ->and(strpos($menuItems, 'Backups'))->toBeLessThan(strpos($menuItems, 'Logs'))
            ->and(strpos($menuItems, 'Logs'))->toBeLessThan(strpos($menuItems, 'Terminal'));
    }
});

it('shows configuration sidebars as a mobile link grid and a desktop rail instead of native selects', function () {
    expect(file_get_contents(resource_path('views/livewire/project/database/configuration.blade.php')))
        ->toContain('<x-database.configuration-sidebar :database="$database" :current-route="$currentRoute" />')
        ->toContain('xl:grid-cols-[210px_minmax(0,1fr)]')
        ->not->toContain('sub-menu-wrapper');

    foreach ([
        'views/components/database/configuration-sidebar.blade.php',
        'views/livewire/project/service/configuration.blade.php',
        'views/livewire/project/service/index.blade.php',
        'views/components/service-database/sidebar.blade.php',
        'views/components/server/sidebar.blade.php',
        'views/components/server/sidebar-proxy.blade.php',
        'views/components/server/sidebar-sentinel.blade.php',
        'views/components/server/sidebar-security.blade.php',
    ] as $view) {
        expect(file_get_contents(resource_path($view)))
            ->toContain('<aside class="application-settings-navigation min-w-0 xl:self-start"')
            ->toContain('class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3')
            ->toContain('xl:grid-cols-1 xl:border-y-0 xl:py-0')
            ->not->toContain('sub-menu-wrapper')
            ->not->toContain('<select')
            ->not->toContain('<optgroup');
    }

    expect(file_get_contents(resource_path('views/components/server/sidebar.blade.php')))
        ->toContain('aria-label="Server configuration sections"')
        ->toContain("'route' => 'server.proxy'")
        ->toContain("'navigate' => false");

    $serverNavbar = file_get_contents(resource_path('views/livewire/server/navbar.blade.php'));

    expect($serverNavbar)
        ->toContain('<div class="mb-3 w-full lg:hidden">')
        ->toContain('<div class="w-full xl:hidden">')
        ->toContain('<x-split-action id="server-mobile-actions" class="mb-3 flex w-full">')
        ->toContain('<button id="server-mobile-restart-proxy-trigger" type="button">')
        ->toContain('<button id="server-mobile-stop-proxy-trigger" type="button">')
        ->not->toContain('navbar-main')
        ->not->toContain('<select');

    $serverMobileActions = serverMobileActionsMarkup($serverNavbar);

    expect($serverMobileActions)
        ->toContain('Restart Proxy')
        ->toContain('Stop Proxy')
        ->toContain('Refresh Proxy Status')
        ->not->toContain('Traefik');

    expect(strpos($serverMobileActions, 'Restart Proxy'))
        ->toBeLessThan(strpos($serverMobileActions, 'Stop Proxy'))
        ->and(strpos($serverMobileActions, 'Stop Proxy'))
        ->toBeLessThan(strpos($serverMobileActions, 'Refresh Proxy Status'));

    foreach ([
        'views/livewire/server/show.blade.php',
        'views/livewire/server/advanced.blade.php',
        'views/livewire/server/private-key/show.blade.php',
        'views/livewire/server/ca-certificate/show.blade.php',
        'views/livewire/server/cloud-provider-token/show.blade.php',
        'views/livewire/server/cloudflare-tunnel.blade.php',
        'views/livewire/server/docker-cleanup.blade.php',
        'views/livewire/server/destinations.blade.php',
        'views/livewire/server/log-drains.blade.php',
        'views/livewire/server/charts.blade.php',
        'views/livewire/server/swarm.blade.php',
        'views/livewire/server/delete.blade.php',
        'views/livewire/server/proxy/show.blade.php',
        'views/livewire/server/proxy/logs.blade.php',
        'views/livewire/server/proxy/dynamic-configurations.blade.php',
        'views/livewire/server/sentinel/show.blade.php',
        'views/livewire/server/sentinel/logs.blade.php',
        'views/livewire/server/security/patches.blade.php',
        'views/livewire/server/security/terminal-access.blade.php',
    ] as $view) {
        expect(file_get_contents(resource_path($view)))
            ->toContain('server-settings-workspace application-settings-workspace')
            ->toContain('xl:grid-cols-[210px_minmax(0,1fr)]')
            ->toContain('<x-server.sidebar')
            ->not->toContain('sub-menu-wrapper')
            ->not->toContain('class="flex flex-col h-full gap-8 md:flex-row');
    }
});

function serverMobileActionsMarkup(string $navbar): string
{
    $actionsPosition = strpos($navbar, 'id="server-mobile-actions"');
    $restartModalPosition = strpos($navbar, '<x-modal-confirmation', $actionsPosition);

    if ($actionsPosition === false || $restartModalPosition === false || $actionsPosition > $restartModalPosition) {
        return '';
    }

    return substr($navbar, $actionsPosition, $restartModalPosition - $actionsPosition);
}
