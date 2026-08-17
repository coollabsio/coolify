<?php

it('shows a loading overlay while resource selection steps load', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($view)
        ->toContain('wire:loading.class="pointer-events-none opacity-40 blur-[2px]"')
        ->toContain('wire:loading.flex')
        ->toContain('selectServer,selectDestination,selectProject,selectEnvironment')
        ->toContain('Loading selection…')
        ->toContain('isPaletteTransitioning')
        ->toContain('runPaletteTransition')
        ->toContain('Loading…');
});

it('uses a single Alpine result renderer for every command palette result type', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($view)
        ->not->toContain('Create mode (server-rendered path)')
        ->not->toContain('!$wire.isCreateMode')
        ->toContain("<!-- Command palette -->\n    <div x-cloak")
        ->not->toContain("<!-- Command palette -->\n    <template x-teleport=\"body\">")
        ->toContain("<div wire:ignore>\n                        <template x-if=\"searchQuery.length")
        ->toContain('x-for="(result, index) in searchResults"')
        ->toContain('x-for="[categoryName, items] in Object.entries(groupedCreatableItems)"');
});

it('skips hidden command palette results during keyboard navigation', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($view)
        ->toContain('filter(item => item.offsetParent !== null)');
});

it('preselects the first result in every resource selection step', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($view)
        ->toContain('preselectFirstResult()')
        ->toContain('this.selectedIndex = 0;')
        ->toContain('results[0].focus();')
        ->and(substr_count($view, 'x-init="preselectFirstResult()"'))->toBe(4);
});

it('opens the command palette without changing page scrollbar visibility', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($view)
        ->not->toContain("document.body.style.overflow = value ? 'hidden' : ''")
        ->not->toContain("document.body.style.overflow = ''");
});

it('animates the command palette with tw animate utilities', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    // Exit animations need fill-mode-forwards: without it the element snaps
    // back to full opacity when the keyframe animation ends, one frame before
    // Alpine applies display:none, which flashes the palette on close.
    expect($view)
        ->toContain('<div x-show="modalOpen" @click="closeModal()"')
        ->toContain('x-transition:enter="animate-in fade-in-0 zoom-in-95 slide-in-from-top-2 duration-150"')
        ->toContain('x-transition:leave="animate-out fade-out-0 zoom-out-95 slide-out-to-top-2 duration-100 fill-mode-forwards"')
        ->toContain('x-transition:leave="animate-out fade-out-0 duration-100 fill-mode-forwards"')
        ->not->toContain('<div x-show="modalOpen" x-cloak\n        class="fixed inset-0');
});

it('closes the client-side command palette without a Livewire request', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($view)
        ->not->toContain('$wire.closeSearchModal()')
        ->not->toContain('closeTimer');
});

it('closes the mobile sidebar when the command palette opens', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toContain('@open-global-search.window="open = false"');
});

it('keeps palette content intact during the close animation to prevent flicker', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    // closeModal() must only hide the palette immediately; content resets
    // (searchQuery, allSearchableItems) are deferred past the 100ms leave
    // animation so the panel does not collapse while fading out.
    expect($view)
        ->toContain('clearTimeout(this.closeResetTimer);')
        ->toContain("this.closeResetTimer = setTimeout(() => {\n            this.isLoadingInitialData = false;")
        ->toContain("this.searchQuery = '';\n            this.allSearchableItems = [];");
});

it('delays the header spinner so fast cached loads do not flash the icon', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($view)
        ->toContain('showLoadingSpinner')
        ->toContain('this.spinnerTimer = setTimeout(')
        ->toContain('x-show="!showLoadingSpinner"')
        ->toContain('x-show="showLoadingSpinner"')
        ->not->toContain(':class="isLoadingInitialData && \'is-loading\'"');
});
