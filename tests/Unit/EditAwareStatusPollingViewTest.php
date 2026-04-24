<?php

it('uses edit-aware polling in resource heading views', function () {
    $headingViews = [
        __DIR__.'/../../resources/views/livewire/project/application/heading.blade.php',
        __DIR__.'/../../resources/views/livewire/project/database/heading.blade.php',
        __DIR__.'/../../resources/views/livewire/project/service/heading.blade.php',
    ];

    foreach ($headingViews as $view) {
        $contents = file_get_contents($view);

        expect($contents)
            ->toContain("coolifyEditAwarePoller(\$wire, 'checkStatus')")
            ->toContain('@coolify-form-editing-started.window="pause()"')
            ->toContain('@coolify-form-editing-finished.window="resumeAndRefresh()"')
            ->not->toContain('wire:poll.10000ms="checkStatus"');
    }
});

it('tracks focus across editable configuration panes before resuming status refresh', function () {
    $configurationViews = [
        __DIR__.'/../../resources/views/livewire/project/application/configuration.blade.php',
        __DIR__.'/../../resources/views/livewire/project/database/configuration.blade.php',
        __DIR__.'/../../resources/views/livewire/project/service/configuration.blade.php',
        __DIR__.'/../../resources/views/livewire/project/service/index.blade.php',
    ];

    foreach ($configurationViews as $view) {
        $contents = file_get_contents($view);

        expect($contents)
            ->toContain('<x-resources.edit-aware-polling-scripts />')
            ->toContain('x-data="coolifyFormFocusTracker()"')
            ->toContain('@focusin="startEditing()"')
            ->toContain('@focusout="finishEditing()"');
    }
});
