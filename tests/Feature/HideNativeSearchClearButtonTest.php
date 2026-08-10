<?php

test('app css hides native webkit search cancel button to avoid double clear controls', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('input[type="search"]::-webkit-search-cancel-button')
        ->toContain('display: none');
});

test('resource index keeps a single custom clear search control', function () {
    $view = file_get_contents(resource_path('views/livewire/project/resource/index.blade.php'));

    expect(substr_count($view, 'aria-label="Clear search"'))->toBe(1)
        ->and($view)->toContain('type="search"');
});
