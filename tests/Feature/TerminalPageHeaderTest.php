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
            ->toContain("'name' => 'Tropical Storm'")
            ->toContain("'name' => 'Blur Black'")
            ->toContain("'name' => 'Cosmic Purple'")
            ->not->toContain("Shadow's ");
    }
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
