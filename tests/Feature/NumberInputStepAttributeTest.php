<?php

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag);
    view()->share('errors', $errors);
});

it('renders number input with step attribute for decimal values', function () {
    $html = Blade::render('<x-forms.input type="number" id="maxStorage" min="0" step="any" />');

    expect($html)
        ->toContain('type="number"')
        ->toContain('step="any"')
        ->toContain('min="0"');
});
