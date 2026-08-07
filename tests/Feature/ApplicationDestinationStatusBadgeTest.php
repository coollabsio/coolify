<?php

it('uses the shared status summary in the primary application server card', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/destination.blade.php'));
    $applicationSection = str($view)->before('@else')->value();

    expect($applicationSection)
        ->toContain('<x-status-summary :status="$resource->status" />')
        ->not->toContain('<x-status :resource="$resource"');
});
