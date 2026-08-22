<?php

it('renders configurable host copy for S3 endpoints', function () {
    $html = file_get_contents(resource_path('views/components/forms/domain-input.blade.php'));

    expect($html)
        ->toContain('{{ $hostLabel }}')
        ->toContain('{{ $hostPlaceholder }}')
        ->toContain('wire:model="{{ $id }}.host"')
        ->toContain('wire:model="{{ $id }}.port"')
        ->toContain('wire:model="{{ $id }}.path"');
});

it('binds URL parts directly to Livewire without Alpine synchronization', function () {
    $view = file_get_contents(resource_path('views/components/forms/domain-input.blade.php'));

    expect($view)
        ->toContain('wire:model="{{ $id }}.host"')
        ->toContain('wire:model="{{ $id }}.port"')
        ->not->toContain('x-data=')
        ->not->toContain('$watch');
});

it('keeps validation errors attached to the composed endpoint', function () {
    $html = file_get_contents(resource_path('views/components/forms/domain-input.blade.php'));

    expect($html)
        ->toContain('@error($errorId ?? "{$id}.host")')
        ->toContain('href="{{ $validationLink }}"')
        ->toContain('Set them here.');
});
