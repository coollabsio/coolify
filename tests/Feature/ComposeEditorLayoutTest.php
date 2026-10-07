<?php

use Illuminate\Support\Facades\Blade;

it('keeps the saving state as an Alpine button binding', function () {
    $html = Blade::render('<x-forms.button x-bind:disabled="saving">Save changes</x-forms.button>');

    expect($html)->toContain('x-bind:disabled="saving"');
});
