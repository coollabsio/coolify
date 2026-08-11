<?php

test('confirmation modal closes before dispatching an event that can open another modal', function () {
    $modal = file_get_contents(resource_path('views/components/modal-confirmation.blade.php'));

    expect($modal)->toMatch(
        '/if \(dispatchEvent\) \{\s*modalOpen = false;\s*\$nextTick\(\(\) => \$wire\.dispatch\(dispatchEventType, dispatchEventMessage\)\);/s'
    );
});
