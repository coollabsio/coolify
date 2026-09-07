<?php

/**
 * Terminal-related UI must use the redesign "browser-terminal" reicon, not the
 * older "terminal" (four-way arrows) glyph.
 */
test('terminal and console views use the browser-terminal icon', function () {
    $files = [
        resource_path('views/components/navbar.blade.php'),
        resource_path('views/components/backup-sidebar.blade.php'),
        resource_path('views/components/server/sidebar-security.blade.php'),
        resource_path('views/livewire/terminal/index.blade.php'),
        resource_path('views/livewire/project/shared/terminal.blade.php'),
        resource_path('views/livewire/project/shared/execute-container-command.blade.php'),
        resource_path('views/livewire/project/shared/scheduled-task/all.blade.php'),
        resource_path('views/livewire/project/shared/scheduled-task/executions.blade.php'),
        resource_path('views/livewire/project/database/backup-executions.blade.php'),
        resource_path('views/livewire/server/security/terminal-access.blade.php'),
    ];

    foreach ($files as $path) {
        $contents = file_get_contents($path);

        expect($contents)
            ->not->toMatch('/name=["\']terminal["\']/')
            ->not->toMatch('/icon-name=["\']terminal["\']/')
            ->not->toMatch("/'icon'\\s*=>\\s*'terminal'/");
    }
});

test('reicon pack includes browser-terminal glyph', function () {
    $path = resource_path('views/components/reicon.blade.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain("'browser-terminal' =>");
});
