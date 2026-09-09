<?php

/**
 * Resource Limits nav must use the reicon "cpu" glyph (not subscription card).
 */
test('application and database resource limits menus use the cpu icon', function () {
    $application = file_get_contents(resource_path('views/livewire/project/application/configuration.blade.php'));
    $database = file_get_contents(resource_path('views/livewire/project/database/configuration.blade.php'));
    $reicon = file_get_contents(resource_path('views/components/reicon.blade.php'));

    expect($application)
        ->toMatch("/'Resource Limits'\\s*=>\\s*'cpu'/")
        ->not->toMatch("/'Resource Limits'\\s*=>\\s*'subscription'/");

    expect($database)
        ->toMatch("/'label'\\s*=>\\s*'Resource Limits'[\\s\\S]{0,120}?'icon'\\s*=>\\s*'cpu'/")
        ->not->toMatch("/'label'\\s*=>\\s*'Resource Limits'[\\s\\S]{0,120}?'icon'\\s*=>\\s*'subscription'/");

    expect($reicon)
        ->toContain("'cpu' =>");
});
