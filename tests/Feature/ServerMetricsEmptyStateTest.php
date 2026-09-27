<?php

test('sentinel-required metrics state does not repeat an unavailable badge', function () {
    $view = file_get_contents(resource_path('views/livewire/server/charts.blade.php'));
    $sentinelRequiredState = str($view)->after('@else')->before('@endif')->toString();

    expect($sentinelRequiredState)
        ->toContain('title="Sentinel is required"')
        ->not->toContain('status="Unavailable"');
});

test('disabled metrics state does not repeat a disabled badge', function () {
    $view = file_get_contents(resource_path('views/livewire/server/charts.blade.php'));
    $disabledState = str($view)
        ->after('@elseif ($server->isSentinelEnabled())')
        ->before('@else')
        ->toString();

    expect($disabledState)
        ->toContain('title="Metrics are disabled"')
        ->not->toContain('status="Disabled"');
});
