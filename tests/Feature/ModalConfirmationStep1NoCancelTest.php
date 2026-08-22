<?php

use Illuminate\Support\Facades\Blade;

/**
 * Step 1 (checkbox options) should only show Continue — dismiss is the header X.
 */
test('modal confirmation step 1 does not render a cancel button', function () {
    $modal = file_get_contents(resource_path('views/components/modal-confirmation.blade.php'));

    $step1 = Str::after($modal, '<!-- Step 1: Select actions -->');
    $step1 = Str::before($step1, '<!-- Step 2: Confirm deletion -->');

    expect($step1)
        ->toContain('step1ButtonText')
        ->not->toContain('Cancel');
});

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
