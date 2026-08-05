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
        ->toContain("['label' => 'Runtime'")
        ->toContain("['label' => 'Terminal'")
        ->and($databaseSidebar)
        ->toContain("['label' => 'Backups'")
        ->toContain("['label' => 'Runtime'")
        ->toContain("['label' => 'Terminal'");
});

it('matches application action bar behavior for services and databases', function () {
    $service = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    $database = file_get_contents(resource_path('views/livewire/project/database/heading.blade.php'));

    foreach ([$service, $database] as $heading) {
        expect($heading)
            ->toContain('xl:fixed xl:top-14 xl:right-4')
            ->toContain('xl:w-auto')
            ->toContain('Actions')
            ->toContain('listbox-panel top-full! right-0! left-auto!')
            ->not->toContain('hidden lg:block lg:h-12');
    }

    expect($service)->toContain('id="service-desktop-actions"')
        ->and($database)->toContain('id="database-desktop-actions"');
});

it('keeps database and service sidebar sections in the application sequence', function () {
    $database = file_get_contents(resource_path('views/components/database/configuration-sidebar.blade.php'));
    $service = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));

    expect($database)
        ->toContain("'Settings' => ['General', 'Environment Variables', 'Persistent Storage', 'Backups', 'Servers', 'Import Backup']")
        ->toContain("'Automation' => ['Webhooks', 'Healthcheck']")
        ->toContain("'Logs' => ['Runtime']")
        ->toContain("'Operations' => ['Terminal', 'Resource Limits', 'Resource Operations', 'Metrics', 'Tags', 'Danger Zone']");

    expect($service)
        ->toContain("'Settings' => ['General', 'Domains', 'Environment Variables', 'Persistent Storage', 'Backups']")
        ->toContain("'Automation' => ['Scheduled Tasks', 'Webhooks']")
        ->toContain("'Logs' => ['Runtime']")
        ->toContain("'Operations' => ['Terminal', 'Resource Operations', 'Tags', 'Danger Zone']");
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
        ->toContain("'Logs' => ['Runtime']")
        ->toContain("'Operations' => ['Terminal', 'Resource Operations', 'Tags', 'Danger Zone']");
});
