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

test('compose file loading waits for the user to confirm the file location', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));

    expect($view)
        ->toContain('x-on:click="$wire.dispatch(\'loadCompose\', false)"')
        ->not->toContain('x-init="$wire.dispatch(\'loadCompose\', true)"');
});

test('docker compose heading separates its title and action', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));

    expect($view)
        ->toContain('<div x-data="{ showRaw: true }" class="mt-5">')
        ->toContain('<div class="mb-2 flex items-center justify-between gap-4">');
});

test('unsaved changes ignore compose initialization state', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));
    $unsavedBar = str($view)
        ->after('<x-unsaved-bar action="submit"')
        ->before('/>')
        ->toString();

    expect($unsavedBar)
        ->toContain('targets="')
        ->toContain('name,description')
        ->not->toContain('initLoadingCompose')
        ->not->toContain('dockerComposeRaw')
        ->not->toContain('dockerCompose,');
});

test('onboarding uses the reusable advanced settings component', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));
    $onboarding = file_get_contents(resource_path('views/livewire/boarding/index.blade.php'));

    expect($view)
        ->toContain('id="dockerComposeCustomBuildCommand"')
        ->not->toContain('<x-forms.collapsible class="pt-4" content-class="grid gap-4">')
        ->not->toContain('The following commands are for advanced use cases.')
        ->and($onboarding)
        ->toContain('<x-forms.collapsible title="Advanced Connection Settings"');
});
