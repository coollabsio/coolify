<?php

it('disables proxy configuration controls and covers the editor while saving', function () {
    $view = file_get_contents(resource_path('views/livewire/server/proxy.blade.php'));

    expect($view)
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="submit,resetProxyConfiguration"')
        ->toContain('wire:loading.class="pointer-events-none opacity-50"')
        ->toContain('Updating proxy configuration')
        ->toContain('aria-live="polite"');
});

it('loads only the compose file from the frontend and shows the shared loading indicator', function () {
    $page = file_get_contents(resource_path('views/livewire/server/proxy/show.blade.php'));
    $proxy = file_get_contents(resource_path('views/livewire/server/proxy.blade.php'));
    $component = file_get_contents(app_path('Livewire/Server/Proxy.php'));

    expect($page)
        ->toContain('<livewire:server.proxy :server="$server" />')
        ->not->toContain('<livewire:server.proxy :server="$server" lazy />');

    expect($proxy)
        ->toContain('x-init="$wire.loadProxyConfiguration()"')
        ->toContain('wire:loading.flex wire:target="loadProxyConfiguration"')
        ->toContain('<x-loading text="Loading proxy configuration…" />');

    expect($component)
        ->not->toContain('$this->loadProxyConfiguration();');
});
