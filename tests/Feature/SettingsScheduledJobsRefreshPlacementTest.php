<?php

test('scheduled jobs refresh control lives on the activity section not the navbar', function () {
    $view = file_get_contents(resource_path('views/livewire/settings/scheduled-jobs.blade.php'));

    expect($view)
        ->toContain('<x-settings.layout>')
        ->toContain('settings-section title="Scheduler activity"')
        ->toContain('wire:click="refresh"')
        ->not->toContain('<x-settings.navbar');

    // Refresh should appear after the activity section opens, not before.
    $activityPos = strpos($view, 'settings-section title="Scheduler activity"');
    $refreshPos = strpos($view, 'wire:click="refresh"');

    expect($activityPos)->not->toBeFalse()
        ->and($refreshPos)->not->toBeFalse()
        ->and($refreshPos)->toBeGreaterThan($activityPos);
});
