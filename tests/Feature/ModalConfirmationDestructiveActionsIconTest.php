<?php

/**
 * Destructive confirmation action lists must use reicon trash glyphs,
 * not dismiss/close X icons or raw stroke SVGs.
 */
test('modal confirmation action list uses trash reicon markers', function () {
    $modal = file_get_contents(resource_path('views/components/modal-confirmation.blade.php'));

    expect($modal)
        ->toContain('<x-reicon name="trash" class="mt-0.5 size-3.5 shrink-0" />')
        ->not->toContain('d="M6 18L18 6M6 6l12 12"');

    // Action bullets use trash; header dismiss may still use close X.
    $actionSection = Str::after($modal, 'The following actions will be performed:');
    $actionSection = Str::before($actionSection, '@if (!$disableTwoStepConfirmation)');

    expect($actionSection)
        ->toContain('name="trash"')
        ->not->toContain('name="x"');
});
