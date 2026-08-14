<?php

/**
 * Resource layer-2 navs use one unified pill bar: menus on the left, actions on the right.
 */
it('uses a single unified navbar for application, service, database, and server headings', function () {
    $files = [
        resource_path('views/livewire/project/application/heading.blade.php'),
        resource_path('views/livewire/project/service/heading.blade.php'),
        resource_path('views/livewire/project/database/heading.blade.php'),
        resource_path('views/livewire/server/navbar.blade.php'),
    ];

    foreach ($files as $path) {
        $contents = file_get_contents($path);

        expect($contents)->toContain('resource-heading-navbar');
    }

    $application = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    expect($application)
        ->toContain('justify-start')
        ->toContain('xl:justify-end')
        ->toContain('xl:w-auto')
        ->toContain("@teleport('#resource-action-hud-slot')")
        ->not->toContain('xl:fixed')
        ->not->toContain('xl:top-14')
        ->toContain('xl:hidden')
        ->not->toContain('application-primary-tabs')
        ->not->toContain("typeof collapsed !== 'undefined'")
        ->not->toContain('Spacer: in-flow stand-in')
        ->not->toContain('hidden lg:block lg:h-12')
        ->not->toContain('border-l border-neutral-200 pl-1');
});

it('uses interactive status summaries in mobile resource headings', function () {
    $headings = [
        resource_path('views/livewire/project/application/heading.blade.php') => '<x-status-summary :status="$application->status" />',
        resource_path('views/livewire/project/database/heading.blade.php') => '<x-status-summary :status="$database->status" title="Database status" />',
        resource_path('views/livewire/project/service/heading.blade.php') => '<x-status-summary :status="$service->status" title="Service status" container-name="Containers" />',
    ];

    foreach ($headings as $path => $statusSummary) {
        $mobileHeading = str(file_get_contents($path))
            ->after('<div class="mb-3 w-full xl:hidden">')
            ->before('<div class="w-full xl:hidden">')
            ->toString();

        expect($mobileHeading)
            ->toContain('flex min-w-0 flex-col items-start gap-2')
            ->toContain('min-w-0 max-w-full truncate')
            ->toContain($statusSummary)
            ->not->toContain('<x-status-badge');
    }
});

it('docks desktop resource actions in the top bar instead of floating over content', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $files = [
        resource_path('views/livewire/project/application/heading.blade.php'),
        resource_path('views/livewire/project/service/heading.blade.php'),
        resource_path('views/livewire/project/database/heading.blade.php'),
        resource_path('views/livewire/server/navbar.blade.php'),
    ];

    expect($layout)->toContain('id="resource-action-hud-slot"');

    foreach ($files as $path) {
        $contents = file_get_contents($path);

        expect($contents)
            ->toContain("@teleport('#resource-action-hud-slot')")
            ->not->toContain('xl:fixed xl:top-14 xl:right-4');

        $desktopHud = str($contents)->after("@teleport('#resource-action-hud-slot')")->before('@endteleport')->toString();

        expect($desktopHud)
            ->not->toContain('rounded-[10px]')
            ->not->toContain('border-neutral-200')
            ->not->toContain('bg-neutral-100')
            ->not->toContain('dark:bg-white/[0.035]');
    }
});

it('links the service header missing variables warning to environment variables', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));

    expect($heading)
        ->toContain("route('project.service.environment-variables'")
        ->toContain('Required variables missing')
        ->toContain('href="{{ $environmentVariablesUrl }}"');
});

it('places the account menu beside the desktop sidebar toggle while retaining it on mobile', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $navbar = file_get_contents(resource_path('views/components/navbar.blade.php'));
    $accountMenu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($layout)
        ->not->toContain("{{-- Right cluster --}}\n                    <x-top-user-menu />")
        ->toContain('<x-top-user-menu />')
        ->toContain('flex grow min-w-0 flex-col overflow-visible');

    expect(substr_count($layout, '<x-top-user-menu />'))->toBe(1);

    expect($navbar)
        ->toContain('<x-top-user-menu sidebar />')
        ->toContain('Toggle sidebar')
        ->toContain("collapsed ? 'flex-col-reverse justify-center'");

    expect($accountMenu)
        ->toContain("'sidebar' => false")
        ->toContain("'bottom-full! left-0! right-auto! top-auto! mb-1!' => \$sidebar");
});

it('keeps advanced operations in a dedicated Advanced dropdown', function () {
    $application = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $service = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    $applicationAdvanced = file_get_contents(resource_path('views/components/applications/advanced.blade.php'));
    $serviceAdvanced = file_get_contents(resource_path('views/components/services/advanced.blade.php'));
    $links = file_get_contents(resource_path('views/components/applications/links.blade.php'));

    $applicationDesktop = str($application)->after('resource-heading-actions flex')->toString();
    $serviceDesktop = str($service)->after('resource-heading-actions flex')->toString();

    expect($applicationDesktop)
        ->toContain('application-desktop-actions')
        ->toContain('Deploy (without cache)')
        ->not->toContain('<x-applications.advanced')
        ->not->toContain('resource-heading-overflow-separator')
        ->not->toContain('Force deploy without cache')
        ->and($serviceDesktop)
        ->toContain('service-desktop-actions')
        ->toContain('Force Deploy')
        ->toContain('Force Cleanup Containers')
        ->toContain('Restart (pull latest)')
        ->not->toContain('resource-heading-overflow-separator')
        ->not->toContain('Pull Latest Images & Restart');

    expect(strpos($applicationDesktop, '<x-applications.links'))
        ->toBeLessThan(strpos($applicationDesktop, 'application-desktop-actions'));

    expect(strpos($serviceDesktop, '<x-services.links'))
        ->toBeLessThan(strpos($serviceDesktop, 'service-desktop-actions'));

    expect($applicationAdvanced)
        ->toContain('Advanced')
        ->toContain('name="grid"')
        ->not->toContain('Force deploy without cache')
        ->and($serviceAdvanced)
        ->toContain('Advanced')
        ->toContain('name="grid"')
        ->not->toContain('Pull Latest Images & Restart')
        ->not->toContain('Pull latest and restart')
        ->toContain('Force Restart')
        ->toContain('Force Deploy')
        ->toContain('Force Cleanup Containers')
        ->and($links)->toContain("'right-0! left-auto! min-w-60! max-w-96!' => !\$fullWidth")
        ->and($links)->toContain('listbox-option justify-start! gap-2.5!')
        ->and($links)->not->toContain('md:left-0 md:right-auto');

    // Desktop layer uses exactly one unified pill bar (mobile section may still use its own pill).
    expect(substr_count($application, 'resource-heading-navbar'))->toBe(1);
});

it('groups desktop application lifecycle controls in one Actions dropdown', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $desktop = str($heading)->after('resource-heading-actions flex')->toString();

    expect($desktop)
        ->toContain('application-desktop-actions')
        ->toContain('Actions')
        ->toContain('listbox-panel top-full! right-0! left-auto!')
        ->toContain('listbox-option')
        ->toContain('Deploy')
        ->toContain('Restart')
        ->toContain('Stop')
        ->not->toContain('<x-resource-heading-overflow')
        ->not->toContain('<x-applications.deploy');
});

it('raises the desktop top bar while any resource dropdown is open', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $dropdowns = [
        resource_path('views/components/resource-heading-overflow.blade.php'),
        resource_path('views/components/applications/links.blade.php'),
        resource_path('views/components/services/links.blade.php'),
        resource_path('views/components/applications/deploy.blade.php'),
        resource_path('views/components/services/advanced.blade.php'),
        resource_path('views/components/services/restart.blade.php'),
        resource_path('views/components/server/advanced.blade.php'),
    ];

    expect($layout)
        ->toContain('resourceActionsOpen: false')
        ->toContain("'z-[1000]': resourceActionsOpen")
        ->toContain('@resource-actions-toggled.window="resourceActionsOpen = $event.detail.open"');

    foreach ($dropdowns as $dropdown) {
        expect(file_get_contents($dropdown))
            ->toContain("\$dispatch('resource-actions-toggled', { open })");
    }
});

it('groups service restart options in the Actions dropdown', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    $desktop = str($heading)->after('resource-heading-actions flex')->toString();

    expect($desktop)
        ->toContain('service-desktop-actions')
        ->toContain('Restart')
        ->toContain('Restart (pull latest)')
        ->toContain("\$wire.dispatch('pullAndRestartEvent')")
        ->not->toContain('Pull Latest Images & Restart')
        ->not->toContain('<x-services.restart');

    $mobile = str($heading)->before("@teleport('#resource-action-hud-slot')")->toString();

    expect($mobile)
        ->toContain('Restart current version')
        ->toContain('Pull latest and restart')
        ->not->toContain('Pull Latest Images & Restart');
});

it('orders service restart actions before stop and advanced operations', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    $actions = str($heading)->after('id="service-desktop-actions"')->before('@else\n                        <a href')->toString();

    expect(strpos($actions, 'Restart\n'))
        ->toBeLessThan(strpos($actions, 'Restart (pull latest)'))
        ->and(strpos($actions, 'Restart (pull latest)'))
        ->toBeLessThan(strpos($actions, 'Stop'));
});

it('groups application lifecycle options in an Actions dropdown', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $desktop = str($heading)->after('resource-heading-actions flex')->toString();
    $trigger = str($desktop)->after('id="application-desktop-actions"')->before('<div x-cloak')->toString();

    expect($desktop)
        ->toContain('id="application-desktop-actions"')
        ->toContain('Actions')
        ->toContain('Deploy')
        ->toContain('Deploy (without cache)')
        ->toContain('force_deploy_without_cache')
        ->toContain('deploy(true)')
        ->and($trigger)
        ->toContain('button button-highlighted')
        ->not->toContain('play-circle');

    $mobile = str($heading)->before("@teleport('#resource-action-hud-slot')")->toString();

    expect($mobile)
        ->toContain('Deploy (without cache)')
        ->not->toContain('Force deploy without cache');
});

it('places the state-aware no-cache action immediately after deploy or redeploy', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $actions = str($heading)->after('id="application-desktop-actions"')->before('@endteleport')->toString();

    expect($actions)
        ->toContain("str(\$application->status)->startsWith('running') ? 'Redeploy (without cache)' : 'Deploy (without cache)'")
        ->toContain("str(\$application->status)->startsWith('running') ? 'force_deploy_without_cache' : 'deploy(true)'");

    expect(strpos($actions, 'wire:click="deploy"'))
        ->toBeLessThan(strpos($actions, 'Redeploy (without cache)'))
        ->and(strpos($actions, 'Redeploy (without cache)'))
        ->toBeLessThan(strpos($actions, 'Restart'));
});

it('uses the shared Coollabs gradient for primary resource actions in the desktop header', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.application-heading-actions .button-highlighted')
        ->toContain('@apply button-highlighted;');
});

it('uses iconless highlighted Actions dropdowns for service and proxy lifecycle controls', function () {
    foreach ([
        resource_path('views/livewire/project/service/heading.blade.php') => 'service-desktop-actions',
        resource_path('views/livewire/server/navbar.blade.php') => 'server-desktop-actions',
    ] as $path => $id) {
        $heading = file_get_contents($path);
        $trigger = str($heading)->after("id=\"{$id}\"")->before('<div x-cloak')->toString();

        expect($trigger)
            ->toContain('button button-highlighted')
            ->toContain('Actions')
            ->not->toContain('play-circle');
    }
});

it('places the Traefik dashboard last in the server Actions menu', function () {
    $navbar = file_get_contents(resource_path('views/livewire/server/navbar.blade.php'));
    $actions = str($navbar)->after('id="server-desktop-actions"')->before('@endcan')->toString();

    expect(strpos($actions, 'Refresh Proxy Status'))
        ->toBeLessThan(strpos($actions, 'Traefik Dashboard'));
});

it('keeps database lifecycle controls direct and highlights the primary action', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/database/heading.blade.php'));
    $desktop = str($heading)->after('resource-heading-actions flex')->before('@endteleport')->toString();

    expect($desktop)
        ->toContain('Restart')
        ->toContain('Stop')
        ->toContain('button button-highlighted')
        ->not->toContain('<x-reicon')
        ->not->toContain('<x-resource-heading-overflow');
});

it('renders configuration warnings as navbar popovers instead of floating notifications', function () {
    $checker = file_get_contents(resource_path('views/livewire/project/shared/configuration-checker.blade.php'));
    $warning = file_get_contents(resource_path('views/components/configuration-warning.blade.php'));

    expect($checker)
        ->toContain("@teleport('#configuration-warning-hud-slot')")
        ->toContain("@teleport('#configuration-warning-hud-slot-mobile')")
        ->toContain('<x-configuration-warning :diff="$configurationDiff" />')
        ->not->toContain('<x-popup-small position="top-right"')
        ->and($warning)
        ->toContain('aria-label="Configuration changes not applied"')
        ->toContain('<span class="hidden text-xs font-medium lg:inline">Changes pending</span>')
        ->toContain('The latest configuration has not been applied')
        ->toContain('fixed top-14 left-1/2')
        ->toContain('-translate-x-1/2')
        ->toContain('lg:absolute lg:top-full lg:right-0 lg:left-auto')
        ->toContain('lg:translate-x-0')
        ->toContain('@click.outside="open = false"')
        ->toContain('@keydown.escape.window="open = false"')
        ->toContain("\$dispatch('open-configuration-diff')");
});

it('renders only one configuration warning on database runtime logs', function () {
    $logs = file_get_contents(resource_path('views/livewire/project/shared/logs.blade.php'));
    $databaseHeading = file_get_contents(resource_path('views/livewire/project/database/heading.blade.php'));

    expect($logs)
        ->not->toContain("    <livewire:project.shared.configuration-checker :resource=\"\$resource\" />\n\n    @if (\$type === 'application')")
        ->and($databaseHeading)
        ->toContain('<livewire:project.shared.configuration-checker :resource="$database" />');
});

it('renders only one configuration warning on database and service terminal pages', function () {
    $terminal = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));

    expect($terminal)
        ->not->toContain("@elseif (\$type === 'database')\n        <livewire:project.shared.configuration-checker :resource=\"\$resource\" />")
        ->not->toContain("@elseif (\$type === 'service')\n        <livewire:project.shared.configuration-checker :resource=\"\$resource\" />");
});

it('renders only one configuration warning on the service volume backups page', function () {
    $volumeBackups = file_get_contents(resource_path('views/livewire/project/service/volume-backup/index.blade.php'));

    expect($volumeBackups)
        ->not->toContain('<livewire:project.shared.configuration-checker :resource="$service" />')
        ->toContain('<livewire:project.service.heading :service="$service"');
});

it('moves application backups from the top tabs into the settings sidebar', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $configuration = file_get_contents(resource_path('views/livewire/project/application/configuration.blade.php'));
    $backup = file_get_contents(resource_path('views/livewire/project/application/backup/index.blade.php'));

    expect($heading)->not->toContain("'label' => 'Backups'")
        ->and($configuration)->toContain('<x-application.configuration-sidebar')
        ->and($backup)->toContain('<x-application.configuration-sidebar');
});

it('moves application terminal and logs from the top tabs into the settings sidebar', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $sidebar = file_get_contents(resource_path('views/components/application/configuration-sidebar.blade.php'));

    expect($heading)
        ->not->toContain("'label' => 'Terminal'")
        ->not->toContain("'label' => 'Deployment'")
        ->not->toContain("'label' => 'Runtime'")
        ->and($sidebar)
        ->toContain("'label' => 'Terminal'")
        ->toContain("'label' => 'Deployment Logs'")
        ->toContain("'label' => 'Runtime Logs'")
        ->toContain("'Observe & troubleshoot' => ['Runtime Logs', 'Deployment Logs', 'Terminal', 'Metrics']")
        ->toContain("'Operations' => ['Resource Operations', 'Resource Limits', 'Rollback'");
});

it('groups application automation pages separately from build and deploy', function () {
    $sidebar = file_get_contents(resource_path('views/components/application/configuration-sidebar.blade.php'));

    expect($sidebar)
        ->toContain("'Settings' => ['General', 'Domains', 'Environment Variables', 'Persistent Storage', 'Advanced', 'Swarm', 'Healthcheck']")
        ->toContain("'Deploy' => ['Git Source', 'Servers', 'Preview Deployments']")
        ->toContain("'Automation' => ['Scheduled Tasks', 'Webhooks', 'Backups']")
        ->toContain("'Operations' => ['Resource Operations', 'Resource Limits', 'Rollback'");
});

it('centers the rollback image loading state across the card', function () {
    $rollback = file_get_contents(resource_path('views/livewire/project/application/rollback.blade.php'));

    expect($rollback)
        ->toContain('class="w-full" wire:target="loadImages" wire:loading')
        ->toContain('flex items-center justify-center');
});

it('shows pull request loading feedback only on the action button', function () {
    $previews = file_get_contents(resource_path('views/livewire/project/application/previews.blade.php'));

    expect($previews)
        ->toContain('wire:click="load_prs"')
        ->not->toContain('wire:loading.remove wire:target="load_prs"')
        ->not->toContain('wire:loading wire:target="load_prs"')
        ->not->toContain('Loading pull requests…');
});

it('shows loading feedback while a service deployment starts', function () {
    $service = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));

    expect($service)
        ->toContain('deploying: false')
        ->toContain('@service-deploy-finished.window="deploying = false"')
        ->toContain('<x-loading-on-button x-show="deploying"')
        ->toContain("window.dispatchEvent(new CustomEvent('service-deploy-finished'))");
});

it('uses neutral icons for non-destructive resource actions', function () {
    foreach (['application', 'service', 'database'] as $resource) {
        $heading = file_get_contents(resource_path("views/livewire/project/{$resource}/heading.blade.php"));

        expect($heading)
            ->not->toContain('class="size-3.5 text-orange-500')
            ->not->toContain('class="size-3.5 text-warning"')
            ->not->toContain('class="size-4 text-warning"')
            ->toContain('name="stop-circle"');
    }
});

it('uses a circular stop icon in application and service action menus', function () {
    $icons = file_get_contents(resource_path('views/components/reicon.blade.php'));

    expect($icons)
        ->toContain("'stop-circle' =>")
        ->toContain('<circle cx="12" cy="12"')
        ->toContain('<rect x="8.25" y="8.25"');

    foreach (['application', 'service'] as $resource) {
        $heading = file_get_contents(resource_path("views/livewire/project/{$resource}/heading.blade.php"));

        expect($heading)
            ->toContain('name="stop-circle" class="size-3.5 text-error"')
            ->not->toContain('name="stop" class="size-3.5 text-error"');
    }
});

it('allows the shared empty state to control the storage backups background', function () {
    $backups = file_get_contents(resource_path('views/livewire/project/application/backup/index.blade.php'));

    expect($backups)
        ->toContain("'application-settings-section-body w-full'")
        ->toContain("'is-flush' => \$backups->isNotEmpty()")
        ->not->toContain('application-settings-section-body is-flush w-full bg-transparent!');
});

it('keeps the application settings sidebar below the fixed header while scrolling', function () {
    $sidebar = file_get_contents(resource_path('views/components/application/configuration-sidebar.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($sidebar)->toContain('application-settings-navigation')
        ->and($css)->toContain('.application-settings-workspace > .application-settings-navigation')
        ->and($css)->toContain('top: 4rem;')
        ->and($css)->toContain('max-height: calc(100dvh - 5rem);');
});

it('builds application sidebar routes independently of the current request route', function () {
    $sidebar = file_get_contents(resource_path('views/components/application/configuration-sidebar.blade.php'));

    expect($sidebar)
        ->not->toContain('get_route_parameters()')
        ->toContain("'project_uuid' => \$application->environment->project->uuid")
        ->toContain("'environment_uuid' => \$application->environment->uuid")
        ->toContain("'application_uuid' => \$application->uuid");
});

it('keeps the deployment log sidebar fixed in the layout without a top gap', function () {
    $deployment = file_get_contents(resource_path('views/livewire/project/application/deployment/show.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($deployment)->toContain(':flush="true"')
        ->and($css)->toContain('.application-settings-navigation.is-flush')
        ->and($css)->toContain('position: static;')
        ->and($css)->toContain('overflow: visible;');
});

it('uses the same mobile heading gap on deployment pages as application settings', function () {
    $configuration = file_get_contents(resource_path('views/livewire/project/application/configuration.blade.php'));
    $deploymentIndex = file_get_contents(resource_path('views/livewire/project/application/deployment/index.blade.php'));
    $deploymentShow = file_get_contents(resource_path('views/livewire/project/application/deployment/show.blade.php'));

    expect($configuration)->toContain('application-settings-workspace mt-4')
        ->and($deploymentIndex)->toContain("'mt-4 max-w-[1180px] lg:mt-0' => ! \$embedded")
        ->and($deploymentShow)->toContain('application-settings-workspace mt-4')
        ->toContain('lg:mt-0');
});

it('removes desktop top spacing from the deployment log viewer', function () {
    $deployment = file_get_contents(resource_path('views/livewire/project/application/deployment/show.blade.php'));

    expect($deployment)->toContain("'mt-2 flex flex-1 min-h-0 flex-col overflow-hidden lg:mt-0'");
});

it('uses a distinct runtime log icon in the sidebar', function () {
    $sidebar = file_get_contents(resource_path('views/components/application/configuration-sidebar.blade.php'));

    expect($sidebar)->toContain("'Runtime Logs' => 'unordered-list'");
});

it('shows deployment history above the selected deployment logs', function () {
    $indexClass = file_get_contents(app_path('Livewire/Project/Application/Deployment/Index.php'));
    $indexView = file_get_contents(resource_path('views/livewire/project/application/deployment/index.blade.php'));
    $showView = file_get_contents(resource_path('views/livewire/project/application/deployment/show.blade.php'));

    expect($indexClass)
        ->toContain('public bool $embedded = false;')
        ->toContain('public ?string $selectedDeploymentUuid = null;')
        ->and($indexView)
        ->toContain("'data-table-row-active' => \$selectedDeploymentUuid")
        ->and($showView)
        ->toContain('<livewire:project.application.deployment.index :embedded="true"')
        ->toContain(':selected-deployment-uuid="$deployment_uuid"');
});

it('fits the combined deployment history and logs within the desktop viewport', function () {
    $indexClass = file_get_contents(app_path('Livewire/Project/Application/Deployment/Index.php'));
    $showView = file_get_contents(resource_path('views/livewire/project/application/deployment/show.blade.php'));

    expect($indexClass)->toContain('$this->defaultTake = 3;')
        ->and($showView)
        ->toContain('xl:h-[calc(100dvh-7.5rem)]')
        ->toContain('xl:overflow-hidden')
        ->toContain('xl:flex-1');
});

it('uses only the healthcheck toggle action to communicate enabled state', function () {
    $healthcheck = file_get_contents(resource_path('views/livewire/project/shared/health-checks.blade.php'));

    expect($healthcheck)
        ->not->toContain('<x-status-badge')
        ->toContain('buttonTitle="Enable"')
        ->toContain('Disable');
});

it('keeps Links dropdowns outside the scrollable tabs strip', function () {
    $application = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $service = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));
    $tabsComponent = file_get_contents(resource_path('views/components/resource-heading-tabs.blade.php'));

    // Application Links are standalone on mobile and next to actions on desktop.
    expect($application)
        ->not->toContain('<x-resource-heading-tabs')
        ->toContain('resource-heading-menus')
        ->toContain('<x-applications.links');

    expect($service)
        ->not->toContain('<x-resource-heading-tabs')
        ->toContain('resource-heading-menus')
        ->toContain('<x-services.links');

    expect($css)
        ->toContain('.resource-heading-tabs::-webkit-scrollbar')
        ->toContain('scrollbar-width: none')
        ->toContain('.resource-heading-tabs-control')
        ->toContain('.resource-heading-tabs-control-icon');

    // Kumo-style overflow chevrons live on the shared tabs component.
    expect($tabsComponent)
        ->toContain('Scroll tabs left')
        ->toContain('Scroll tabs right')
        ->toContain('scrollByDir')
        ->toContain('canStart')
        ->toContain('canEnd')
        ->toContain('resource-heading-tabs-control is-start')
        ->toContain('resource-heading-tabs-control is-end')
        ->toContain('scrollActiveIntoView')
        ->toContain('findActiveTab')
        ->toContain('livewire:navigated');
});

it('shows an icon in application and service Links controls', function () {
    $applicationLinks = file_get_contents(resource_path('views/components/applications/links.blade.php'));
    $serviceLinks = file_get_contents(resource_path('views/components/services/links.blade.php'));
    $serviceLinksComponent = file_get_contents(app_path('View/Components/Services/Links.php'));

    expect($applicationLinks)->toContain("@props(['application', 'fullWidth' => false])")
        ->toContain('<x-reicon name="external-link"')
        ->and($serviceLinksComponent)->toContain('public bool $fullWidth = false')
        ->and($serviceLinks)
        ->toContain('<x-reicon name="external-link"')
        ->and($applicationLinks)->not->toContain('<x-external-link')
        ->and($serviceLinks)->not->toContain('<x-external-link');
});

it('visually distinguishes production and pull request application links', function () {
    $links = file_get_contents(resource_path('views/components/applications/links.blade.php'));

    expect($links)
        ->toContain('Production')
        ->toContain('PR #{{ data_get($preview, \'pull_request_id\') }}')
        ->not->toContain("PR{{ data_get(\$preview, 'pull_request_id') }} |");
});

it('shows application and service Links on mobile headings', function () {
    $application = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $service = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));

    $mobileApplicationSection = str($application)->between('class="w-full xl:hidden"', 'class="hidden w-full items-center xl:fixed')->toString();
    $mobileServiceSection = str($service)->between('class="w-full md:hidden"', 'class="hidden w-full items-center md:flex')->toString();

    expect($mobileApplicationSection)
        ->toContain('<x-applications.links')
        ->toContain('full-width')
        ->toContain('resource-heading-menus')
        ->not->toContain('<x-resource-heading-tabs')
        ->not->toContain("'label' => 'Settings'");

    $applicationLinks = file_get_contents(resource_path('views/components/applications/links.blade.php'));
    expect($applicationLinks)
        ->toContain("'button w-full justify-between' => \$fullWidth")
        ->toContain("'left-0! right-0! w-full! min-w-0! max-w-none!' => \$fullWidth")
        ->toContain('<x-reicon name="chevron-down"');

    expect($mobileServiceSection)
        ->toContain('<x-services.links')
        ->toContain('full-width')
        ->toContain('resource-heading-menus')
        ->not->toContain('<x-resource-heading-tabs');

    $serviceLinks = file_get_contents(resource_path('views/components/services/links.blade.php'));
    expect($serviceLinks)
        ->toContain("'button w-full justify-between' => \$fullWidth")
        ->toContain("'left-0! right-0! w-full! min-w-0! max-w-none!' => \$fullWidth")
        ->toContain('<x-reicon name="chevron-down"')
        ->toContain('No links available');

    // One mobile + one desktop instance.
    expect(substr_count($application, '<x-applications.links'))->toBe(2);
    expect(substr_count($service, '<x-services.links'))->toBe(2);
});

it('uses overflow scroll arrows on resource heading navbars', function () {
    $files = [
        resource_path('views/livewire/server/navbar.blade.php'),
    ];

    foreach ($files as $path) {
        expect(file_get_contents($path))->toContain('<x-resource-heading-tabs');
    }
});
