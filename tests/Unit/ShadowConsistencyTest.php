<?php

function cssSource(string $file): string
{
    return file_get_contents(__DIR__.'/../../resources/css/'.$file);
}

function bladeSource(string $path): string
{
    return file_get_contents(__DIR__.'/../../resources/views/'.$path);
}

it('gives the shared popover surface the dropdown lift, not the modal lift', function () {
    $utilities = cssSource('utilities.css');

    expect($utilities)
        ->toContain('@utility surface-popover')
        ->toContain('var(--shadow-dropdown)');

    // The surface-popover block itself must not fall back to the heavy modal shadow.
    preg_match('/@utility surface-popover\s*\{(.*?)\}/s', $utilities, $match);
    expect($match[1] ?? '')
        ->toContain('var(--shadow-dropdown)')
        ->not->toContain('var(--shadow-modal)');
});

it('renders toasts and change-pending popovers with the shared dropdown-level surface', function (string $path) {
    $source = bladeSource($path);

    expect($source)
        ->toContain('surface-popover')
        ->not->toContain('--shadow-modal');
})->with([
    'components/toast.blade.php',
    'components/configuration-warning.blade.php',
    'components/proxy-configuration-warning.blade.php',
    'components/popup-small.blade.php',
    'livewire/deployments-indicator.blade.php',
]);

it('uses the dropdown shadow on floating menus instead of the modal shadow', function (string $path) {
    expect(bladeSource($path))
        ->toContain('shadow-dropdown')
        ->not->toContain('shadow-modal');
})->with([
    'livewire/server/show.blade.php',
    'livewire/project/application/deployment/show.blade.php',
]);

it('gives the command palette the shared dropdown lift', function () {
    $app = cssSource('app.css');

    preg_match('/\.command-palette\s*\{(.*?)\}/s', $app, $match);
    expect($match[1] ?? '')
        ->toContain('var(--shadow-dropdown)')
        ->not->toContain('var(--shadow-modal)');
});
