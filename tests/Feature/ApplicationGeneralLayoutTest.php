<?php

test('compose actions are grouped with the application details header', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));
    $applicationDetails = str($view)
        ->after('class="application-details-card">')
        ->before('</x-application.settings-section>')
        ->toString();

    expect($applicationDetails)
        ->toContain('<x-slot:actions>')
        ->toContain('x-on:click="$wire.dispatch(\'loadCompose\', false)"')
        ->toContain('Reload compose');
});

test('docker compose heading separates its title and action', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));

    expect($view)->toContain('<div class="flex items-center gap-4">');
});
