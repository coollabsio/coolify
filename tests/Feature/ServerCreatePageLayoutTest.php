<?php

test('server creation uses the standard page title without redundant navigation', function () {
    $view = file_get_contents(resource_path('views/livewire/server/create.blade.php'));

    expect($view)
        ->toContain('<h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">New server</h1>')
        ->toContain('class="mb-5 flex min-h-9 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"')
        ->not->toContain('Back to servers')
        ->not->toContain('title="Add a server"');
});

test('provider pages only show the token action in the account panel', function () {
    $view = file_get_contents(resource_path('views/livewire/server/create.blade.php'));

    expect($view)
        ->not->toContain('New token')
        ->not->toContain('new-server-token-')
        ->not->toContain('tokenProviderName');
});
