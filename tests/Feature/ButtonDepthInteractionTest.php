<?php

test('standard buttons use raised hover and pressed depth states', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toContain('--button-depth-color: rgb(0 0 0 / 0.22);')
        ->toContain('--button-depth: 0 2px 0 var(--button-depth-color);')
        ->toContain('--button-depth-hover: 0 3px 0 var(--button-depth-color);')
        ->toContain('.button-highlighted:not(:disabled)')
        ->toContain('--button-depth-color: color-mix(in oklab, var(--color-coollabs) 52%, black);')
        ->toContain('.button:not(:disabled):hover')
        ->toContain('transform: translateY(-1px);')
        ->toContain('.button:not(:disabled):active')
        ->toContain('transform: translateY(2px);')
        ->not->toContain('transform: translateY(1px);')
        ->not->toContain('transform: translateY(3px);')
        ->toContain('box-shadow: none;');
});
