<?php

test('monaco editor binds to livewire through x-modelable and wire:model', function () {
    $contents = file_get_contents(resource_path('views/components/forms/monaco-editor.blade.php'));

    expect($contents)
        ->toContain('monacoContent: $wire.{{ $id }} ?? \'\',')
        ->toContain('x-modelable="monacoContent" wire:model="{{ $id }}"')
        ->toContain('this.monacoContent = value;')
        ->not->toContain('@entangle($id)')
        ->not->toContain('x-ref="livewireInput"')
        ->not->toContain('syncingFromLivewire');
});
