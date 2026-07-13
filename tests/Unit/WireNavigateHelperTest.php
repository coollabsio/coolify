<?php

it('uses wire navigate without hover prefetching in the helper', function () {
    $helperFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/shared.php');

    expect($helperFile)
        ->toContain("return (\$settings->is_wire_navigate_enabled ?? true) ? 'wire:navigate' : '';")
        ->toContain("return 'wire:navigate';")
        ->not->toContain('wire:navigate.hover');
});
