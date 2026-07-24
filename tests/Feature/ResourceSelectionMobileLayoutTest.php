<?php

it('stacks the resource search and category filter on mobile', function () {
    $selectView = file_get_contents(resource_path('views/livewire/project/new/select.blade.php'));

    expect($selectView)
        ->toContain('flex flex-col gap-2 items-stretch sm:flex-row sm:items-start')
        ->toContain('input-sticky w-full sm:flex-1')
        ->toContain('relative w-full sm:w-auto')
        ->toContain('w-full sm:w-64')
        ->not->toContain('flex gap-2 items-start">')
        ->not->toContain('input-sticky flex-1"');
});

it('matches search placeholder color to standard inputs', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    // Extract the input-sticky utility block
    expect($utilities)->toMatch('/@utility input-sticky \{[^}]*placeholder:text-neutral-300 dark:placeholder:text-neutral-700/s');

    // Same placeholder tokens as the standard .input utility
    expect($utilities)->toMatch('/@utility input \{[^}]*placeholder:text-neutral-300 dark:placeholder:text-neutral-700/s');
});
