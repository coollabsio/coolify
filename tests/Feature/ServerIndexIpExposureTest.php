<?php

test('server index does not expose server IP addresses', function () {
    $view = file_get_contents(resource_path('views/livewire/server/index.blade.php'));

    expect($view)
        ->not->toContain("'address' => \$server->ip")
        ->not->toContain('<div>Address</div>')
        ->not->toContain('x-text="server.address"')
        ->not->toContain('server.address,');
});
