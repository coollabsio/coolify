<?php

it('shows the initial terminal target launcher and keeps the header picker for switching', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));

    expect($view)
        ->toContain('targetChosen: @js($selected_uuid !== \'default\')')
        ->toContain('data-terminal-target-picker="launcher"')
        ->toContain('x-show="!targetChosen"')
        ->toContain('x-show="targetChosen"')
        ->toContain('this.targetChosen = true;');
});

it('shows the pre-session target list as a page card instead of a full-height console canvas', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));

    $pickerBranch = str($view)
        ->after("@if (\$selected_uuid === 'default')")
        ->before("\n        @else")
        ->toString();

    expect($view)
        ->toContain("@if (\$selected_uuid === 'default')")
        ->toContain('data-terminal-target-picker="page"')
        ->toContain('data-terminal-target-canvas')
        ->toContain("@else\n        <div wire:key=\"terminal-session-canvas\" data-terminal-session-canvas")
        ->and($pickerBranch)
        ->toContain('<x-application.settings-section class="terminal-target-card"')
        ->toContain('title="Start a terminal session"')
        // The themed console canvas belongs to an open session, not to target selection.
        ->not->toContain('application-console-shell')
        ->not->toContain(':data-console-theme="consoleTheme"')
        ->not->toContain('<x-terminal.theme-selector');
});

it('keeps the pre-session target list scrollable inside the full-width card', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('data-terminal-target-picker="page"')
        ->toContain('aria-label="Filter terminal targets"')
        ->toContain('class="application-settings-workspace flex w-full min-w-0 flex-col"')
        ->toContain('class="terminal-target-card-list"')
        ->and($styles)
        ->toContain('.terminal-target-card .application-settings-section-body')
        ->toMatch('/\.terminal-target-card-list\s*\{[^}]*max-height:\s*min\(70vh, 34rem\);/s')
        ->toMatch('/\.terminal-target-group-label\s*\{[^}]*position:\s*sticky;/s')
        // The wrapped filter needs the same gutter on both sides of the header, and the
        // override must match the base header selector's specificity to win.
        ->toMatch('/\.application-settings-section\.terminal-target-card > :is\(header, \.application-settings-section-header\)\s*\{\s*padding-right:\s*1rem;/s');
});

it('splits target rows into a name and a server column that only shows with several servers', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain("'name' => \$container['name'],")
        ->toContain("'server' => \$server->name,")
        ->toContain('multipleServers: @js($servers->count() > 1)')
        ->toContain('x-text="target.name"')
        ->toContain('class="terminal-target-item-server" x-text="target.server"')
        ->toContain('x-show="multipleServers"')
        // The label keeps both parts so filtering still matches the server name.
        ->toContain("'label' => \$server->name.' · '.\$container['name'],")
        ->and($styles)
        ->toContain('.terminal-target-item-server');
});

it('loads targets inside the themed session picker with an accent scrollbar', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('Finding available servers and containers…')
        ->toContain('terminal-target-list')
        ->toContain('Loading servers and containers…')
        ->toContain("'--terminal-scrollbar': themeAccents[consoleTheme]")
        ->and($styles)
        ->toContain('.terminal-target-list')
        ->toContain('var(--terminal-scrollbar')
        ->toContain('.terminal-target-list::-webkit-scrollbar-thumb');
});

it('uses the same padded themed canvas for the active terminal session', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('data-terminal-session-canvas')
        ->toContain('p-3 sm:p-6')
        ->toContain('terminal-session-panel mt-8')
        ->and($styles)
        ->toContain('.terminal-session-panel')
        ->toContain('border-radius: 0.75rem;')
        ->toMatch('/\.terminal-session-panel\s*\{[^}]*border:\s*0;/s')
        ->toMatch('/\.terminal-session-panel\s*\{[^}]*background:\s*transparent;/s')
        ->toMatch('/\.terminal-session-panel\s*\{[^}]*box-shadow:\s*none;/s');
});

it('keeps pre-connection and connected terminal canvases in distinct Livewire DOM branches', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));

    expect($view)
        ->toContain('wire:key="terminal-target-canvas"')
        ->toContain('wire:key="terminal-session-canvas"');
});

it('opens the global terminal outside Livewire navigation like resource terminals', function () {
    $navbar = file_get_contents(resource_path('views/components/navbar.blade.php'));

    expect($navbar)
        ->toContain('<a title="Terminal"')
        ->toContain('href="{{ route(\'terminal\') }}"')
        ->not->toMatch('/<a title="Terminal"[^>]*wireNavigate\(\)/s');
});

it('keeps the server terminal navigation active during Livewire requests', function () {
    $sidebar = file_get_contents(resource_path('views/components/server/sidebar.blade.php'));
    $navbar = file_get_contents(resource_path('views/livewire/server/navbar.blade.php'));

    expect($sidebar)
        ->toContain("'active' => \$activeMenu === 'terminal'")
        ->and($navbar)
        ->toContain("'active' => \$currentRoute === 'server.command'");
});

it('uses floating rounded controls instead of the legacy terminal header bar', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));

    expect($view)
        ->toContain('terminal-session-toolbar')
        ->toContain('terminal-session-target-trigger')
        ->toContain('<x-terminal.theme-selector')
        ->not->toContain('application-console-header flex h-[30px]');
});

it('offers the console theme selector only while a session owns the canvas', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $selector = file_get_contents(resource_path('views/components/terminal/theme-selector.blade.php'));

    expect(substr_count($view, '<x-terminal.theme-selector'))
        ->toBe(1)
        ->and($selector)
        ->toContain('terminal-theme-trigger flex h-8 items-center gap-2 rounded-md px-2.5 text-xs font-medium')
        ->and($view)
        ->toContain('terminal-session-toolbar absolute top-3 right-3 left-3')
        ->not->toContain('terminal-theme-trigger');
});

it('uses the same borderless control style for target and theme selectors', function () {
    $globalView = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $resourceView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));

    expect($globalView.$resourceView)
        ->not->toContain('terminal-session-target-trigger flex h-9')
        ->not->toContain('terminal-session-target-trigger flex h-8 min-w-0 max-w-sm cursor-pointer items-center gap-2 rounded-md border')
        ->toContain('terminal-session-target-trigger flex h-8')
        ->toContain('rounded-md px-2.5');
});

it('reuses one chevron theme selector before and during terminal sessions', function () {
    $globalView = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $resourceView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));
    $selector = file_get_contents(resource_path('views/components/terminal/theme-selector.blade.php'));

    expect(substr_count($globalView.$resourceView, '<x-terminal.theme-selector'))
        ->toBe(2)
        ->and($selector)
        ->toContain('terminal-theme-trigger')
        ->toContain('viewBox="0 0 12 12"')
        ->toContain('m3.5 4.75 2.5 2.5 2.5-2.5');
});

it('keeps the theme selector in the floating session toolbar', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));

    expect($view)
        ->toContain('terminal-session-toolbar absolute top-3 right-3 left-3')
        ->toContain('<x-terminal.theme-selector')
        ->not->toContain('class="absolute top-3 right-3 z-20"');
});

it('updates the owning terminal theme directly so its label and canvas stay synchronized', function () {
    $globalView = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $resourceView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));
    $selector = file_get_contents(resource_path('views/components/terminal/theme-selector.blade.php'));

    expect($globalView.$resourceView)
        ->toContain('setTheme(theme)')
        ->and($selector)
        ->toContain('@click="setTheme(')
        ->not->toContain("\$dispatch('terminal-theme-selected'");
});

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
        // The viewport lock only applies once a session owns the canvas.
        ->toContain("class=\"{{ \$selected_uuid === 'default' ? '' : 'terminal-page' }} application-settings-form\"")
        ->toContain("'terminal-page-console min-h-0 w-full flex-1 overflow-hidden'")
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
        ->toContain("attributeFilter: ['class', 'data-theme', 'style']")
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
    $selector = file_get_contents(resource_path('views/components/terminal/theme-selector.blade.php'));

    foreach ([$terminalView, $consoleView] as $view) {
        expect($view)->toContain('<x-terminal.theme-selector');
    }

    expect($selector)
        ->toContain('console-theme-selector')
        ->toContain('border-neutral-200 bg-white')
        ->toContain('dark:bg-[#111113]')
        ->toContain('text-neutral-600 transition-colors')
        ->toContain('dark:text-white/65');

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
        ->before("@else\n        <section class=\"mt-8 mb-0!")
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
