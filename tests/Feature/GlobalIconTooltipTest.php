<?php

it('uses the custom tooltip for icon actions across layouts', function () {
    $baseLayout = file_get_contents(resource_path('views/layouts/base.blade.php'));
    $tooltip = file_get_contents(resource_path('views/components/icon-tooltip.blade.php'));

    expect($baseLayout)->toContain('<x-icon-tooltip />')
        ->and($tooltip)
        ->toContain("closest('button, a, [data-tooltip]')")
        ->toContain("target.matches('[data-tooltip], .icon-button')")
        ->toContain("target.hasAttribute('aria-label')")
        ->toContain('target.childElementCount === 1')
        ->toContain("target.firstElementChild?.matches('svg')")
        ->not->toContain("target.querySelector('svg')")
        ->toContain("target.matches('[data-icon-tooltip-ignore]')")
        ->toContain("target.removeAttribute('title')")
        ->toContain('role="tooltip"')
        ->toContain('aria-label')
        ->toContain('fixed z-[100]');
});

it('does not reposition an already active tooltip during nested mouseover events', function () {
    $tooltip = file_get_contents(resource_path('views/components/icon-tooltip.blade.php'));

    expect($tooltip)
        ->toContain('if (target === this.activeTarget && this.visible) return;');
});

it('keeps a tooltip hidden until its measured position is applied', function () {
    $tooltip = file_get_contents(resource_path('views/components/icon-tooltip.blade.php'));

    expect($tooltip)
        ->toContain('positioned: false')
        ->toContain('this.positioned = false;')
        ->toContain('this.$nextTick(() => this.positioned = true);')
        ->toContain("positioned ? 'visible' : 'invisible'");
});

it('centers tooltips on the trigger and keeps them within the viewport', function () {
    $tooltip = file_get_contents(resource_path('views/components/icon-tooltip.blade.php'));

    expect($tooltip)
        ->toContain('this.x = rect.left + rect.width / 2;')
        ->toContain('Math.min(window.innerWidth - width - 8, this.x - width / 2)')
        ->not->toContain('-translate-x-1/2');
});
