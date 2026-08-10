<?php

it('uses a subtle warning yellow focus ring in dark mode', function () {
    $appStyles = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    $v5Styles = file_get_contents(dirname(__DIR__, 2).'/resources/css/v5/app.css');

    expect($appStyles)
        ->toContain('ring-coollabs dark:ring-warning/70')
        ->and($v5Styles)
        ->toMatch('/\.dark\s*\{[^}]*--ring:\s*color-mix\(in srgb, var\(--warning\) 70%, transparent\);/s');
});
