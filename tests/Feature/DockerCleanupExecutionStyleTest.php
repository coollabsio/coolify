<?php

test('docker cleanup execution output uses light and dark theme surfaces', function () {
    $view = file_get_contents(resource_path('views/livewire/server/docker-cleanup-executions.blade.php'));

    expect($view)
        ->toContain('bg-white text-neutral-700')
        ->toContain('dark:bg-neutral-950 dark:text-neutral-300');
});

test('docker cleanup executions show a loading state while opening an execution', function () {
    $view = file_get_contents(resource_path('views/livewire/server/docker-cleanup-executions.blade.php'));

    expect($view)
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="selectExecution({{ data_get($execution, \'id\') }})"')
        ->toContain('wire:loading.remove')
        ->toContain('<x-loading wire:loading');
});

test('docker cleanup explains application image retention options', function () {
    $view = file_get_contents(resource_path('views/livewire/server/docker-cleanup.blade.php'));

    expect($view)
        ->toContain('Choose Keep retained images to follow each application’s Images to keep setting under Rollback.')
        ->toContain('Delete all old images ignores that setting and keeps only the running image.');
});
