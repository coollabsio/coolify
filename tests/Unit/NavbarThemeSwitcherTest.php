<?php

test('expanded navbar theme item cycles themes without a right side selector', function () {
    $navbar = file_get_contents(__DIR__.'/../../resources/views/components/navbar.blade.php');

    expect(substr_count($navbar, '@click.stop="cycleTheme()"'))->toBe(2)
        ->and($navbar)->not->toContain('aria-label="Theme switcher"')
        ->and($navbar)->not->toContain('ml-auto flex items-center gap-0.5')
        ->and($navbar)->not->toContain('@click.stop="setTheme(\'light\')"')
        ->and($navbar)->not->toContain('@click.stop="setTheme(\'system\')"')
        ->and($navbar)->not->toContain('@click.stop="setTheme(\'dark\')"')
        ->and($navbar)->toContain('<span class="text-left menu-item-label">Theme</span>');
});
