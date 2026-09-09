<?php

/**
 * Application configuration Rollback nav must use the reicon "time-back" glyph.
 */
test('application rollback menu uses the time-back icon', function () {
    $configuration = file_get_contents(resource_path('views/livewire/project/application/configuration.blade.php'));
    $reicon = file_get_contents(resource_path('views/components/reicon.blade.php'));

    expect($configuration)
        ->toMatch("/'Rollback'\\s*=>\\s*'time-back'/")
        ->not->toMatch("/'Rollback'\\s*=>\\s*'logout'/");

    expect($reicon)
        ->toContain("'time-back' =>");
});
