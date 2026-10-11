<?php

it('keeps pre-connection and connected terminal canvases in distinct Livewire DOM branches', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));

    expect($view)
        ->toContain('wire:key="terminal-target-canvas"')
        ->toContain('wire:key="terminal-session-canvas"');
});
