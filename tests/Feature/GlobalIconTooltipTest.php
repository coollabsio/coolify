<?php

it('uses the custom tooltip for icon actions across layouts', function () {
    $baseLayout = file_get_contents(resource_path('views/layouts/base.blade.php'));
    $tooltip = file_get_contents(resource_path('views/components/icon-tooltip.blade.php'));

    expect($baseLayout)->toContain('<x-icon-tooltip />')
        ->and($tooltip)
        ->toContain("closest('button, a, [data-tooltip]')")
        ->toContain("target.querySelector('svg')")
        ->toContain("target.removeAttribute('title')")
        ->toContain('role="tooltip"')
        ->toContain('aria-label')
        ->toContain('fixed z-[100]');
});
