<?php

test('app layouts always reserve space for the vertical scrollbar', function (string $layout) {
    $contents = file_get_contents(dirname(__DIR__, 2).'/'.$layout);

    expect($contents)->toMatch('/<body(?:\s+[^>]*)?class="[^"]*overflow-y-scroll[^"]*"[^>]*>/');
})->with(['resources/views/layouts/base.blade.php']);
