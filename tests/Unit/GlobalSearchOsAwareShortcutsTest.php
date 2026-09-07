<?php

it('uses OS-aware modifier key labels in the global search palette', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');

    expect($bladeFile)
        ->toContain('modKeyLabel')
        ->toContain("return /Mac|iPhone|iPad|iPod/i.test(platform) || /Mac OS X|Macintosh/i.test(ua) ? '⌘' : 'Ctrl+';")
        ->toContain("x-text=\"modKeyLabel + 'K'\"")
        ->not->toContain('>⌘K</span>');
});

it('uses OS-aware modifier key labels in the sidebar search trigger', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/components/navbar.blade.php');

    expect($bladeFile)
        ->toContain('modKeyLabel')
        ->toContain("return /Mac|iPhone|iPad|iPod/i.test(platform) || /Mac OS X|Macintosh/i.test(ua) ? '⌘' : 'Ctrl+';")
        ->toContain(":title=\"'Search (Press / or ' + modKeyLabel + 'K)'\"")
        ->toContain("x-text=\"modKeyLabel + 'K'\"")
        ->not->toContain('>⌘K</kbd>')
        ->not->toContain('title="Search (Press / or ⌘K)"');
});
