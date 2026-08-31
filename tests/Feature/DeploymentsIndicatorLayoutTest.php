<?php

it('positions the deployments indicator from the sidebar collapsed state', function () {
    $indicatorView = file_get_contents(resource_path('views/livewire/deployments-indicator.blade.php'));
    $layoutView = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($indicatorView)
        ->toContain('transition-[left] duration-200')
        ->toContain(":class=\"collapsed ? 'lg:left-16' : 'lg:left-56'\"")
        ->not->toContain('fixed bottom-0 z-60 mb-4 left-0 lg:left-56 ml-4');

    expect($layoutView)
        ->toContain('<div x-data="{')
        ->toContain('<livewire:deployments-indicator />');

    expect(strpos($layoutView, '<div x-data="{'))
        ->toBeLessThan(strpos($layoutView, '<livewire:deployments-indicator />'));
});

it('uses the redesigned elevated surface and status badge patterns', function () {
    $indicatorView = file_get_contents(resource_path('views/livewire/deployments-indicator.blade.php'));

    expect($indicatorView)
        ->toContain('var(--coollabs-elevated)')
        ->toContain('var(--coollabs-line)')
        ->toContain('var(--shadow-modal)')
        ->toContain('var(--coollabs-hairline)')
        ->toContain('<x-status-badge')
        ->toContain('name="chevron-down"')
        ->toContain("'In progress'")
        ->toContain("'Queued'")
        ->toContain('dark:text-fg')
        ->toContain('dark:border-coolgray-300')
        ->toContain('$this->shouldShow')
        ->toContain('updateShouldShowFromPath')
        ->toContain('livewire:navigated')
        ->not->toContain('dark:bg-coolgray-100')
        ->not->toContain('dark:bg-coolgray-200')
        ->not->toContain('text-gray-800')
        ->not->toContain("str_replace('_', ' ', \$deployment->status)");
});

it('uses solid surfaces in every deployments indicator state', function () {
    $indicatorView = file_get_contents(resource_path('views/livewire/deployments-indicator.blade.php'));

    expect($indicatorView)
        ->not->toContain('reduceOpacity')
        ->not->toContain('transition-opacity')
        ->not->toContain('x-transition:enter-start="opacity-0')
        ->not->toContain('x-transition:leave-end="opacity-0')
        ->not->toContain('dark:bg-white/[')
        ->not->toContain('dark:hover:bg-white/[')
        ->not->toContain('dark:border-white/[');
});
