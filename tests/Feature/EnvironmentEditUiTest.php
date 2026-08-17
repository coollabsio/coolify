<?php

test('environment delete button label is simply Delete', function () {
    $view = file_get_contents(resource_path('views/livewire/project/delete-environment.blade.php'));

    expect($view)
        ->toContain('buttonTitle="Delete"')
        ->not->toContain('buttonTitle="Delete Environment"');
});

test('environment delete section stacks on small screens', function () {
    $view = file_get_contents(resource_path('views/livewire/project/environment-edit.blade.php'));

    expect($view)
        ->toContain('flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-start sm:justify-between')
        ->toContain('livewire:project.delete-environment');
});

test('app-tab utility includes icon text spacing', function () {
    $css = file_get_contents(resource_path('css/utilities.css'));

    expect($css)->toMatch('/@utility app-tab \{[^}]*gap-1/s');
});
