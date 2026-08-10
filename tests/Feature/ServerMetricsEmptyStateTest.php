<?php

test('sentinel-required metrics state does not repeat an unavailable badge', function () {
    $view = file_get_contents(resource_path('views/livewire/server/charts.blade.php'));
    $sentinelRequiredState = str($view)->after('@else')->before('@endif')->toString();

    expect($sentinelRequiredState)
        ->toContain('title="Sentinel is required"')
        ->not->toContain('status="Unavailable"');
});
