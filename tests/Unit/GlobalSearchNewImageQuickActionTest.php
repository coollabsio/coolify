<?php

it('renders GlobalSearch database icons as image assets instead of raw logo HTML', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');

    expect($bladeFile)
        ->not->toContain('$item[\'logo_html\']')
        ->not->toContain('x-html="item.logo_html"');

    expect($globalSearchFile)->not->toContain("'logo_html' =>");
});
