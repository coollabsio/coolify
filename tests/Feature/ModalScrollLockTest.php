<?php

use Illuminate\Support\Facades\Blade;

test('input modal renders without requiring a modal id', function () {
    $html = Blade::render('<x-modal-input>Modal content</x-modal-input>');

    expect($html)->toContain('Modal content');
});
