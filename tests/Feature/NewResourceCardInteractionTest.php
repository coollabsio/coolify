<?php

it('only deploys from keyboard events on the resource card itself', function () {
    $view = file_get_contents(resource_path('views/livewire/project/new/select.blade.php'));

    expect(substr_count($view, '@keydown.enter.self.prevent='))->toBe(4)
        ->and(substr_count($view, '@keydown.space.self.prevent='))->toBe(4)
        ->and($view)->not->toContain('@keydown.enter.prevent=')
        ->and($view)->not->toContain('@keydown.space.prevent=');
});
