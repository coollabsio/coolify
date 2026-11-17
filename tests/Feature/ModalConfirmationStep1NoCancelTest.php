<?php

use Illuminate\Support\Facades\Blade;

test('modal confirmation lets checkbox ids define their Livewire model binding', function () {
    $checkbox = Blade::render(<<<'BLADE'
        <x-forms.checkbox id="deleteVolumes"
            x-on:change="toggleAction('deleteVolumes')"
            x-bind:checked="selectedActions.includes('deleteVolumes')" />
    BLADE);

    expect($checkbox)
        ->toContain('x-on:change="toggleAction(\'deleteVolumes\')"')
        ->toContain('x-bind:checked="selectedActions.includes(\'deleteVolumes\')"')
        ->toContain('wire:model=deleteVolumes')
        ->toMatch('/id="deleteVolumes-[a-zA-Z0-9]{24}"/');
});
