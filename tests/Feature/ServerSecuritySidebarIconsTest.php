<?php

/**
 * Server security nav icons: patching uses bandage; terminal uses browser-terminal.
 */
test('server security sidebar uses bandage and browser-terminal icons', function () {
    $sidebar = file_get_contents(resource_path('views/components/server/sidebar-security.blade.php'));
    $reicon = file_get_contents(resource_path('views/components/reicon.blade.php'));

    expect($sidebar)
        ->toMatch("/'label'\\s*=>\\s*'Server Patching'[\\s\\S]{0,200}?'icon'\\s*=>\\s*'bandage'/")
        ->toMatch("/'label'\\s*=>\\s*'Terminal Access'[\\s\\S]{0,200}?'icon'\\s*=>\\s*'browser-terminal'/")
        ->not->toMatch("/'label'\\s*=>\\s*'Server Patching'[\\s\\S]{0,200}?'icon'\\s*=>\\s*'admin'/")
        ->not->toMatch("/'label'\\s*=>\\s*'Terminal Access'[\\s\\S]{0,200}?'icon'\\s*=>\\s*'terminal'/");

    expect($reicon)
        ->toContain("'bandage' =>")
        ->toContain("'browser-terminal' =>");
});

test('terminal access page status tile uses browser-terminal icon', function () {
    $contents = file_get_contents(resource_path('views/livewire/server/security/terminal-access.blade.php'));

    expect($contents)
        ->toContain('name="browser-terminal"')
        ->not->toContain('name="terminal"');
});
