<?php

it('moves service and database page navigation into their sidebars', function () {
    $serviceHeading = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    $databaseHeading = file_get_contents(resource_path('views/livewire/project/database/heading.blade.php'));
    $serviceConfiguration = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));
    $databaseSidebar = file_get_contents(resource_path('views/components/database/configuration-sidebar.blade.php'));

    expect($serviceHeading)->not->toContain('<x-resource-heading-tabs')
        ->and($databaseHeading)->not->toContain('<x-resource-heading-tabs')
        ->and($serviceConfiguration)
        ->toContain("['label' => 'Backups'")
        ->toContain("['label' => 'Runtime Logs'")
        ->toContain("['label' => 'Terminal'")
        ->and($databaseSidebar)
        ->toContain("['label' => 'Backups'")
        ->toContain("['label' => 'Runtime Logs'")
        ->toContain("['label' => 'Terminal'");
});

it('uses a full page load for database backup imports', function () {
    $sidebar = file_get_contents(resource_path('views/components/database/configuration-sidebar.blade.php'));

    expect($sidebar)->toContain("['label' => 'Import Backup', 'route' => 'project.database.import-backup', 'icon' => 'upload', 'navigate' => false");
});

it('matches application action bar behavior for services and databases', function () {
    $service = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    $database = file_get_contents(resource_path('views/livewire/project/database/heading.blade.php'));

    foreach ([$service, $database] as $heading) {
        expect($heading)
            ->toContain('@teleport(\'#resource-action-hud-slot\')')
            ->toContain('xl:w-auto')
            ->toContain('<x-resource-heading-overflow')
            ->not->toContain('hidden lg:block lg:h-12');
    }

    expect($service)->toContain('id="service-desktop-actions"')
        ->and($database)->toContain('id="database-desktop-actions"');
});

it('groups database and service navigation by user workflow', function () {
    $database = file_get_contents(resource_path('views/components/database/configuration-sidebar.blade.php'));
    $serviceSidebars = [
        file_get_contents(resource_path('views/components/service/configuration-sidebar.blade.php')),
        file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php')),
    ];

    expect($database)
        ->toContain("'Settings' => ['General', 'Environment Variables', 'Persistent Storage', 'Healthcheck']")
        ->toContain("'Observe & troubleshoot' => ['Runtime Logs', 'Terminal', 'Metrics']")
        ->toContain("'Deploy' => ['Servers']")
        ->toContain("'Automation' => ['Webhooks', 'Backups', 'Import Backup']")
        ->toContain("'Operations' => ['Resource Operations', 'Resource Limits', 'Tags', 'Danger Zone']");

    foreach ($serviceSidebars as $serviceSidebar) {
        expect($serviceSidebar)
            ->toContain("'Settings' => ['General', 'Domains', 'Environment Variables', 'Persistent Storage']")
            ->toContain("'Observe & troubleshoot' => ['Runtime Logs', 'Terminal']")
            ->toContain("'Automation' => ['Scheduled Tasks', 'Webhooks', 'Backups']")
            ->toContain("'Operations' => ['Resource Operations', 'Tags', 'Danger Zone']");
    }
});

it('groups application navigation by user workflow', function () {
    $application = file_get_contents(resource_path('views/components/application/configuration-sidebar.blade.php'));

    expect($application)
        ->toContain("'Settings' => ['General', 'Domains', 'Environment Variables', 'Persistent Storage', 'Advanced', 'Swarm', 'Healthcheck']")
        ->toContain("'Observe & troubleshoot' => ['Runtime Logs', 'Deployment Logs', 'Terminal', 'Metrics']")
        ->toContain("'Deploy' => ['Git Source', 'Servers', 'Preview Deployments']")
        ->toContain("'Automation' => ['Scheduled Tasks', 'Webhooks', 'Backups']")
        ->toContain("'Operations' => ['Resource Operations', 'Resource Limits', 'Rollback', 'Tags', 'Danger Zone']");
});

it('shows the database sidebar on backup pages', function () {
    $configuration = file_get_contents(resource_path('views/livewire/project/database/configuration.blade.php'));
    $backups = file_get_contents(resource_path('views/livewire/project/database/backup/index.blade.php'));
    $sidebar = file_get_contents(resource_path('views/components/database/configuration-sidebar.blade.php'));

    expect($configuration)->toContain('<x-database.configuration-sidebar')
        ->and($backups)
        ->toContain('<x-database.configuration-sidebar')
        ->toContain('current-route="project.database.backup.index"')
        ->and($sidebar)
        ->toContain("str(\$currentRoute)->startsWith('project.database.backup')");
});

it('shows the service sidebar on the storage backups page', function () {
    $backups = file_get_contents(resource_path('views/livewire/project/service/volume-backup/index.blade.php'));

    expect($backups)
        ->toContain('<x-service.configuration-sidebar :service="$service"')
        ->toContain('current-route="project.service.volume-backups.index"')
        ->toContain('xl:grid-cols-[210px_minmax(0,1fr)]');
});

it('combines service database and storage backups in one section', function () {
    $backups = file_get_contents(resource_path('views/livewire/project/service/volume-backup/index.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect(substr_count($backups, '<x-application.settings-section '))->toBe(1)
        ->and(substr_count($backups, 'data-table-header backup-table-grid'))->toBe(1)
        ->and($backups)->toContain('Storage backup')->toContain('Database backup')
        ->not->toContain('data-table-header scheduled-backups-table-grid')
        ->not->toContain('>Database backups</h3>')
        ->not->toContain('>Storage backups</h3>')
        ->toContain("'application-settings-section-body w-full'")
        ->toContain('class="data-table w-full overflow-x-auto"')
        ->toContain('backup-table-grid service-backup-table-grid')
        ->not->toContain('<span class="text-right">Executions</span>')
        ->toContain('class="data-table-row backup-table-grid text-[13px]')
        ->toContain('class="listbox-option justify-start! gap-2.5!"')
        ->toContain('x-data="{ dropdownOpen: false }"')
        ->toContain('class="listbox-panel left-0! right-auto! z-[90]! w-52! min-w-52! sm:left-auto! sm:right-0!"')
        ->not->toContain('<x-dropdown');

    expect($styles)->toContain('.service-backup-table-grid');
});

it('links compose database backups to the unified service backups page', function () {
    $sidebar = file_get_contents(resource_path('views/components/service-database/sidebar.blade.php'));

    expect($sidebar)
        ->toContain("'route' => 'project.service.volume-backups.index'")
        ->toContain("'parameters' => \$serviceParameters")
        ->not->toContain("'route' => 'project.service.database.backups'");
});

it('uses a distinct backup icon across resource sidebars', function () {
    $sidebars = [
        resource_path('views/components/application/configuration-sidebar.blade.php'),
        resource_path('views/components/database/configuration-sidebar.blade.php'),
        resource_path('views/livewire/project/service/configuration.blade.php'),
    ];

    foreach ($sidebars as $sidebar) {
        $contents = file_get_contents($sidebar);

        expect($contents)->toMatch("/['\"]Backups['\"].*['\"]database['\"]/s");
    }
});

it('shows the database sidebar on runtime logs', function () {
    $logs = file_get_contents(resource_path('views/livewire/project/shared/logs.blade.php'));

    expect($logs)
        ->toContain("in_array(\$type, ['application', 'database', 'service'], true)")
        ->toContain('<x-database.configuration-sidebar :database="$resource" current-route="project.database.logs"')
        ->toContain('loading-state-card');
});

it('shows the database sidebar in the terminal', function () {
    $terminal = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));

    expect($terminal)
        ->toContain("in_array(\$type, ['application', 'database', 'service', 'server'], true)")
        ->toContain('<x-database.configuration-sidebar :database="$resource" current-route="project.database.command"');
});

it('shows the service sidebar on runtime logs and terminal pages', function () {
    $logs = file_get_contents(resource_path('views/livewire/project/shared/logs.blade.php'));
    $terminal = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));
    $sidebar = file_get_contents(resource_path('views/components/service/configuration-sidebar.blade.php'));

    expect($logs)
        ->toContain("in_array(\$type, ['application', 'database', 'service'], true)")
        ->toContain('<x-service.configuration-sidebar :service="$resource" current-route="project.service.logs"')
        ->and($terminal)
        ->toContain("in_array(\$type, ['application', 'database', 'service', 'server'], true)")
        ->toContain('<x-service.configuration-sidebar :service="$resource" current-route="project.service.command"')
        ->and($sidebar)
        ->toContain("'Observe & troubleshoot' => ['Runtime Logs', 'Terminal']")
        ->toContain("'Operations' => ['Resource Operations', 'Tags', 'Danger Zone']");
});
