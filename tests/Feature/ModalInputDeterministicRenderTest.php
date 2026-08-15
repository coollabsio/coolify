<?php

/**
 * Livewire matches DOM nodes by wire:key, then wire:id, then the element id.
 * A modal shell that emits a freshly generated id on every render is therefore
 * treated as a different node and swapped out on the next parent re-render,
 * discarding whatever the user had typed into the open modal.
 */

use Illuminate\Support\Facades\Blade;

test('the modal shell renders identically on every render', function () {
    $render = fn () => Blade::render('<x-modal-input title="Edit item">body</x-modal-input>');

    expect($render())->toBe($render());
});

test('the modal shell does not emit a generated id', function () {
    $modal = file_get_contents(resource_path('views/components/modal-input.blade.php'));

    expect($modal)->not->toContain('uniqid(');
});
