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
