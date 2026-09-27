<?php

test('sponsorship reminder listener is not parsed as a blade directive', function () {
    $view = file_get_contents(resource_path('views/livewire/layout-popups.blade.php'));
    $compiledView = Blade::compileString($view);

    expect($compiledView)
        ->toContain('x-on:show-sponsorship-reminder.window=')
        ->not->toContain('$__env->yieldSection()');
});
