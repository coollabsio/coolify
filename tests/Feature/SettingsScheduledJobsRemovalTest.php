<?php

use Illuminate\Support\Facades\Route;

test('instance settings no longer expose scheduled jobs monitoring', function () {
    $settingsLayout = file_get_contents(resource_path('views/components/settings/layout.blade.php'));

    expect(Route::has('settings.scheduled-jobs'))->toBeFalse()
        ->and($settingsLayout)->not->toContain('Scheduled Jobs')
        ->and($settingsLayout)->not->toContain('settings.scheduled-jobs');
});
