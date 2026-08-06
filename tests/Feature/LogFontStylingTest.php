<?php

it('registers geist mono from a local asset for log surfaces', function () {
    $fontsCss = file_get_contents(resource_path('css/fonts.css'));
    $appCss = file_get_contents(resource_path('css/app.css'));
    $fontPath = resource_path('fonts/geist-mono-variable.woff2');
    $geistSansPath = resource_path('fonts/geist-sans-variable.woff2');

    expect($fontsCss)
        ->toContain("font-family: 'Geist Mono'")
        ->toContain("url('../fonts/geist-mono-variable.woff2')")
        ->toContain("font-family: 'Geist Sans'")
        ->toContain("url('../fonts/geist-sans-variable.woff2')")
        ->and($appCss)
        ->toContain("--font-sans: 'Geist Sans', Inter, sans-serif")
        ->toContain('@apply min-h-screen text-sm font-sans antialiased scrollbar overflow-x-clip;')
        ->toContain("--font-logs: 'Geist Mono'")
        ->toContain("--font-geist-sans: 'Geist Sans'")
        ->and($fontPath)
        ->toBeFile()
        ->and($geistSansPath)
        ->toBeFile();
});

it('uses geist mono for shared logs and terminal rendering', function () {
    $sharedLogsView = file_get_contents(resource_path('views/livewire/project/shared/get-logs.blade.php'));
    $deploymentLogsView = file_get_contents(resource_path('views/livewire/project/application/deployment/show.blade.php'));
    $activityMonitorView = file_get_contents(resource_path('views/livewire/activity-monitor.blade.php'));
    $dockerCleanupView = file_get_contents(resource_path('views/livewire/server/docker-cleanup-executions.blade.php'));
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($sharedLogsView)
        ->toContain('font-logs max-w-full cursor-default')
        ->toContain('font-logs max-w-full whitespace-pre-wrap break-all text-neutral-400')
        ->and($deploymentLogsView)
        ->toContain('flex min-w-0 flex-col font-logs')
        ->toContain('mb-2 font-logs text-neutral-400')
        ->and($activityMonitorView)
        ->toContain('<pre class="font-logs min-w-0 max-w-full whitespace-pre-wrap wrap-anywhere"')
        ->and($dockerCleanupView)
        ->toContain('font-mono')
        ->and($terminalClient)
        ->toContain('"Geist Mono"');
});

it('constrains activity monitor logs inside the available viewport', function () {
    $activityMonitorView = file_get_contents(resource_path('views/livewire/activity-monitor.blade.php'));

    expect($activityMonitorView)
        ->toContain('flex flex-col w-full min-w-0 max-w-full')
        ->toContain('<pre class="font-logs min-w-0 max-w-full whitespace-pre-wrap wrap-anywhere"');
});

it('bounds database startup activity monitor to the slide over height', function () {
    $databaseHeadingView = file_get_contents(resource_path('views/livewire/project/database/heading.blade.php'));

    expect($databaseHeadingView)
        ->toContain('<div wire:ignore class="h-full min-h-0 min-w-0 max-w-full">');
});
