<?php

test('native scrollbars follow the active theme', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toContain('html {')
        ->toContain('color-scheme: light;')
        ->toContain('html.dark {')
        ->toContain('color-scheme: dark;');
});
