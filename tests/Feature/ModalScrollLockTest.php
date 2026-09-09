<?php

use Illuminate\Support\Facades\Blade;

test('input modal renders without requiring a modal id', function () {
    $html = Blade::render('<x-modal-input>Modal content</x-modal-input>');

    expect($html)->toContain('Modal content');
});

test('input modal overlay is fixed to the viewport without its own page scrollbar', function () {
    $html = Blade::render('<x-modal-input>Modal content</x-modal-input>');

    expect($html)
        ->toContain('class="fixed inset-0 z-99 overflow-hidden"')
        ->not->toContain('w-screen overflow-hidden')
        ->not->toContain('class="fixed inset-0 z-99 overflow-y-auto"');
});

test('confirmation modal closes before dispatching an event that can open another modal', function () {
    $modal = file_get_contents(resource_path('views/components/modal-confirmation.blade.php'));

    expect($modal)->toMatch(
        '/if \(dispatchEvent\) \{\s*modalOpen = false;\s*\$nextTick\(\(\) => \$wire\.dispatch\(dispatchEventType, dispatchEventMessage\)\);/s'
    );
});

test('confirmation modal releases its scroll lock before submitting a destructive action', function () {
    $modal = file_get_contents(resource_path('views/components/modal-confirmation.blade.php'));

    expect($modal)
        ->toMatch('/submitting = true;\s*modalOpen = false;\s*\$nextTick\(\(\) => \{\s*submitForm\(\)/s')
        ->toMatch('/if \(result === true\) \{\s*resetModal\(\);/s');
});
