<?php

test('standard buttons use raised hover and pressed depth states', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toContain('--button-depth-color: rgb(0 0 0 / 0.22);')
        ->toContain('--button-depth: 0 2px 0 var(--button-depth-color);')
        ->toContain('--button-depth-hover: 0 3px 0 var(--button-depth-color);')
        ->toContain('--button-depth-color: var(--color-coollabs-300);')
        ->toContain('.dark .button:not(.button-highlighted):not(.button-error):not([isHighlighted]):not(:disabled)')
        ->not->toContain('.dark .button:not(.button-highlighted):not([isHighlighted]):not(:disabled)')
        ->toContain('--button-depth-color: rgb(255 255 255 / 0.08);')
        ->not->toContain('.dark .button.button-highlighted:not(:disabled)')
        ->toContain('.button.button-error:not(:disabled)')
        ->toContain('--button-depth-color: var(--color-red-300);')
        ->toContain('.dark .button.button-error:not(:disabled)')
        ->toContain('--button-depth-color: var(--color-red-800);')
        ->toContain('.button-error:not(:disabled)')
        ->toContain('.button.button-error {')
        ->toContain('.button.button-error:disabled {')
        ->toContain('hover:bg-red-100 hover:text-red-900 dark:hover:bg-red-700 dark:hover:text-white')
        ->not->toContain('hover:bg-red-300 hover:text-white dark:hover:bg-red-800')
        ->not->toContain('button[isError]:not(:disabled)')
        ->toContain('.button:not(:disabled):hover')
        ->toContain('transform: translateY(-1px);')
        ->toContain('transition-duration: 120ms, 120ms, 120ms, 80ms, 80ms;')
        ->toContain('.button:not(:disabled):active')
        ->toContain('transform: translateY(2px);')
        ->not->toContain('transform: translateY(1px);')
        ->not->toContain('transform: translateY(3px);')
        ->toContain('box-shadow: none;');
});
