<?php

use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag);
    view()->share('errors', $errors);
});

it('moves focus into the confirmation modal when it opens', function () {
    $html = Blade::render('<x-modal-confirmation buttonTitle="Delete" />');

    expect($html)
        ->toContain('previouslyFocusedElement: null')
        ->toContain("x-ref=\"confirmationModal\"")
        ->toContain('data-modal-initial-focus')
        ->toContain("const focusTarget = \$refs.confirmationModal?.querySelector('[data-modal-initial-focus], input, textarea, select, button:not([disabled]), [href], [tabindex]:not([tabindex=\\'-1\\'])')")
        ->toContain('focusTarget?.focus();');
});
