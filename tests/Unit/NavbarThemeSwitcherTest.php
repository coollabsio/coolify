<?php

test('navbar does not render the removed theme switcher', function () {
    $navbar = file_get_contents(__DIR__.'/../../resources/views/components/navbar.blade.php');

    expect($navbar)->not->toContain('cycleTheme()')
        ->and($navbar)->not->toContain('aria-label="Theme switcher"')
        ->and($navbar)->not->toContain('@click.stop="setTheme(\'light\')"')
        ->and($navbar)->not->toContain('@click.stop="setTheme(\'system\')"')
        ->and($navbar)->not->toContain('@click.stop="setTheme(\'dark\')"')
        ->and($navbar)->not->toContain('<span class="text-left menu-item-label">Theme</span>');
});
