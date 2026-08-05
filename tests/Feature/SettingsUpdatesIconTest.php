<?php

/**
 * Instance settings Updates nav must use the reicon "refresh3" glyph.
 */
test('settings updates sidebar uses the refresh3 icon', function () {
    $sidebar = file_get_contents(resource_path('views/components/settings/layout.blade.php'));
    $reicon = file_get_contents(resource_path('views/components/reicon.blade.php'));

    expect($sidebar)
        ->toMatch("/'label'\\s*=>\\s*'Updates'[\\s\\S]{0,200}?'icon'\\s*=>\\s*'refresh3'/")
        ->not->toMatch("/'label'\\s*=>\\s*'Updates'[\\s\\S]{0,200}?'icon'\\s*=>\\s*'dashboard'/");

    expect($reicon)
        ->toContain("'refresh3' =>")
        ->toContain('stroke-dasharray="3 3"');
});
