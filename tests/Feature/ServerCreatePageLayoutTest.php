<?php

test('server creation uses the standard page title without redundant navigation', function () {
    $view = file_get_contents(resource_path('views/livewire/server/create.blade.php'));

    expect($view)
        ->toContain('<h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">New server</h1>')
        ->not->toContain('Back to servers')
        ->not->toContain('title="Add a server"');
});
