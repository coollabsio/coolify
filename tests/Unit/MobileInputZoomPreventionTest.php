<?php

it('uses at least sixteen pixel form controls on mobile to prevent input focus zoom', function (string $stylesheet) {
    $css = file_get_contents(dirname(__DIR__, 2).'/'.$stylesheet);

    expect($css)->toContain('@media (max-width: 767px)')
        ->and($css)->toContain('input,')
        ->and($css)->toContain('textarea,')
        ->and($css)->toContain('select')
        ->and($css)->toContain('font-size: 16px');
})->with([
    'current interface' => 'resources/css/app.css',
    'v5 interface' => 'resources/css/v5/app.css',
]);

it('keeps user zoom enabled in the viewport meta tags', function () {
    $layouts = [
        'resources/views/layouts/base.blade.php',
        'resources/views/v5/app.blade.php',
    ];

    foreach ($layouts as $layout) {
        expect(file_get_contents(dirname(__DIR__, 2).'/'.$layout))
            ->not->toContain('maximum-scale')
            ->not->toContain('user-scalable=no');
    }
});
