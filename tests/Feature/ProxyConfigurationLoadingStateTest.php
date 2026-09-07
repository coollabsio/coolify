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
