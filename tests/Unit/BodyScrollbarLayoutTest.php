<?php

test('app layouts let the root element own vertical scrolling', function (string $layout) {
    $contents = file_get_contents(dirname(__DIR__, 2).'/'.$layout);

    expect($contents)->not->toMatch('/<body(?:\s+[^>]*)?class="[^"]*overflow-y-scroll[^"]*"[^>]*>/');
})->with(['resources/views/layouts/base.blade.php']);

test('scroll locking is left to Alpine scrollbar compensation', function () {
    $styles = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

    expect($styles)->not->toMatch('/html\s*\{[^}]*scrollbar-gutter:\s*stable;/s');
});

test('desktop navbar uses the document width', function () {
    $layout = file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/app.blade.php');

    expect($layout)->not->toMatch('/class="[^"]*hidden lg:flex fixed top-0 inset-x-0[^"]*w-screen[^"]*"/');
});
