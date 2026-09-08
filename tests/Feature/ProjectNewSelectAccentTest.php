<?php

it('uses the Coollabs gradient button treatment for deploy actions', function () {
    $view = file_get_contents(resource_path('views/livewire/project/new/select.blade.php'));

    expect($view)
        ->not->toContain('dark:group-hover:text-warning')
        ->and(substr_count($view, 'class="button button-highlighted ml-auto"'))->toBe(4);
});
