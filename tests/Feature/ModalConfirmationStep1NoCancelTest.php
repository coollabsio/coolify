<?php

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
