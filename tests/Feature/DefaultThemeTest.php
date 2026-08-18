<?php

test('new users default to dark mode', function () {
    $layout = file_get_contents(resource_path('views/layouts/base.blade.php'));

    expect($layout)
        ->toContain('<html data-theme="dark"')
        ->toContain("const t = localStorage.theme || 'dark';")
        ->toContain("localStorage.theme = 'dark';")
        ->toContain('<meta name="theme-color" content="#101010" id="theme-color-meta" />');
});
