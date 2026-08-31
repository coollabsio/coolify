<?php

it('uses at least sixteen pixel form controls on mobile to prevent input focus zoom', function (string $stylesheet) {
    $css = file_get_contents(dirname(__DIR__, 2).'/'.$stylesheet);
    $baseLayerStart = strpos($css, '@layer base {');
    $mobileRuleStart = strpos($css, '@media (max-width: 767px)');

    expect($baseLayerStart)->not->toBeFalse()
        ->and($mobileRuleStart)->toBeGreaterThan($baseLayerStart)
        ->and(substr_count(substr($css, $baseLayerStart, $mobileRuleStart - $baseLayerStart), '{'))
        ->toBeGreaterThan(substr_count(substr($css, $baseLayerStart, $mobileRuleStart - $baseLayerStart), '}'))
        ->and($css)->toContain(':root input,')
        ->and($css)->toContain(':root textarea,')
        ->and($css)->toContain(':root select')
        ->and($css)->toContain('font-size: 16px !important;');
})->with(['resources/css/app.css']);

it('keeps user zoom enabled in the viewport meta tags', function () {
    $layouts = ['resources/views/layouts/base.blade.php'];

    foreach ($layouts as $layout) {
        expect(file_get_contents(dirname(__DIR__, 2).'/'.$layout))
            ->not->toContain('maximum-scale')
            ->not->toContain('user-scalable=no');
    }
});
