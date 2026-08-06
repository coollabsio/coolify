<?php

it('places the terminal helper beside the page title instead of under the subtitle', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));

    expect($view)
        ->toContain('terminal-page-header shrink-0')
        ->toContain('<div class="flex items-center gap-2">')
        ->toContain('<h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">Terminal</h1>')
        ->toContain('<x-helper')
        ->toContain('Run commands on reachable servers and containers from the browser.')
        ->not->toContain('flex items-center gap-1.5 text-[13px] text-neutral-500 dark:text-fg-dim');
});

it('locks the terminal page to the viewport so the document does not scroll', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('class="terminal-page application-settings-form"')
        ->toContain('class="terminal-page-console min-h-0 w-full flex-1 overflow-hidden"')
        ->not->toContain('h-[calc(100dvh-11rem)]')
        ->not->toContain('min-h-[32rem]')
        ->and($appCss)
        ->toContain('.terminal-page')
        ->toContain('height: calc(100dvh - 6.75rem)')
        ->toContain('height: calc(100dvh - 7.25rem)')
        ->toContain('overflow: hidden');
});

it('labels console themes without a Shadow\'s prefix', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $consoleView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));

    foreach ([$terminalView, $consoleView] as $view) {
        expect($view)
            ->toContain("['key' => 'system', 'name' => 'System'")
            ->toContain("'name' => 'Tropical Storm'")
            ->toContain("'name' => 'Blur Black'")
            ->toContain("'name' => 'Cosmic Purple'")
            ->not->toContain("Shadow's ");
    }
});

it('defaults to a system console theme that follows the page color mode', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $consoleView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    foreach ([$terminalView, $consoleView] as $view) {
        expect($view)
            ->toContain("consoleTheme: 'system'")
            ->toContain("? savedTheme : 'system'");
    }

    expect($terminalClient)
        ->toContain("'system': createSystemTerminalTheme()")
        ->toContain('new MutationObserver')
        ->toContain("attributeFilter: ['class', 'data-theme']")
        ->and($appCss)
        ->toContain('[data-console-theme="system"]')
        ->toContain('html:not(.dark) .application-console-shell[data-console-theme="system"]')
        ->toContain('--console-theme-border: #d4d4d8')
        ->toContain('background: transparent')
        ->toContain('html.dark .application-console-shell[data-console-theme="system"]');
});

it('uses the page color mode for the console theme selector', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $consoleView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));

    foreach ([$terminalView, $consoleView] as $view) {
        expect($view)
            ->toContain('console-theme-selector')
            ->toContain('border-neutral-200 bg-white')
            ->toContain('dark:bg-[#111113]')
            ->toContain('text-neutral-600 transition-colors')
            ->toContain('dark:text-white/65');
    }

    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($appCss)
        ->toContain('.console-theme-selector')
        ->toContain('scrollbar-color: rgb(161 161 170) transparent')
        ->toContain('html.dark .console-theme-selector')
        ->toContain('scrollbar-color: rgb(255 255 255 / 0.28) transparent');
});

it('shows terminal unavailable without the terminal shell wrapper', function () {
    $consoleView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));

    $unavailableBranch = str($consoleView)
        ->after('@if ($consoleUnavailable)')
        ->before('@else')
        ->toString();

    expect($consoleView)
        ->toContain('$consoleUnavailable')
        ->toContain('@if ($consoleUnavailable)')
        ->toContain('title="Terminal unavailable"')
        ->toContain('icon-name="browser-terminal"')
        // Empty state must not nest inside the themed terminal chrome.
        ->not->toContain('No running containers');

    expect($unavailableBranch)
        ->toContain('<x-empty')
        ->toContain('title="Terminal unavailable"')
        ->not->toContain('application-console-shell')
        ->not->toContain('application-console-header')
        ->not->toContain('Choose terminal theme');
});

it('shows runtime logs unavailable without log viewer chrome', function () {
    $logsView = file_get_contents(resource_path('views/livewire/project/shared/logs.blade.php'));

    $unavailableBranch = str($logsView)
        ->after('@elseif ($logsUnavailable)')
        ->before('@else')
        ->toString();

    expect($logsView)
        ->toContain('$logsUnavailable')
        ->toContain('title="Runtime logs unavailable"')
        ->toContain('icon-name="file-content"')
        ->toContain('class="mt-4 w-full lg:mt-3"')
        ->toContain('application-settings-workspace')
        ->not->toContain('title="No running containers"')
        ->not->toContain('application-settings-form');

    expect($unavailableBranch)
        ->toContain('<x-empty')
        ->toContain('title="Runtime logs unavailable"')
        ->not->toContain('runtime-log-shell')
        ->not->toContain('livewire:project.shared.get-logs')
        ->not->toContain('logs-viewer-toolbar');
});

it('uses server and container icons in the target list but not on the closed dropdown trigger', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));

    expect($view)
        ->toContain("'type' => 'server'")
        ->toContain("'type' => 'container'")
        ->toContain("x-show=\"target.type === 'server'\"")
        ->toContain("x-show=\"target.type === 'container'\"")
        ->toContain('name="servers"')
        ->toContain('name="layers"')
        ->toContain('aria-label="Choose terminal target"')
        // Trigger shows label + chevron only (no leading reicon inside the button).
        ->toMatch('/aria-label="Choose terminal target">\s*<span class="min-w-0 truncate/');
});

it('shows loading targets without a leading terminal icon', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));

    expect($view)
        ->toContain('Loading targets…')
        ->toMatch('/@if \(\$isLoadingContainers\)\s*<span class="min-w-0 truncate text-\[11px\] font-semibold text-white\/55">\s*Loading targets…/')
        ->not->toMatch('/@if \(\$isLoadingContainers\)\s*<x-reicon name="browser-terminal"/');
});
